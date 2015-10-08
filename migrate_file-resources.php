<?php

// File resource migration // CLI script

define('CLI_SCRIPT', 1);

// Run from /admin/cli dir
require_once(dirname(dirname(dirname(__FILE__))).'/config.php');

// Where are the resource files?
$fileresourcefolder = '/'; // Include trailing slash

// Open report file
$reportfile = fopen('resource_migration_report.txt', 'w');

$found = array();
$notfound = array();

$fs = get_file_storage();

$file_record = array(
    'component' => 'mod_resource',
    'filearea' => 'content',
    'filepath' => '/'
);

$fileresources = simplexml_load_file('resource_content.xml') or die('wtf');

// Move resource content into the file area
foreach ($fileresources->children() as $resourcedetails) {

    $courseid = (int) $resourcedetails->field['0'];
    $cmid = (int) $resourcedetails->field['1'];
    $resourceid = (int) $resourcedetails->field['2'];
    $coursename = (string) $resourcedetails->field['3']; // unused
    $resourcename = (string) $resourcedetails->field['4'];
    $filename = (string) $resourcedetails->field['5'];

    if (file_exists($fileresourcefolder . $filename)) {

        $found[] = $filename;

        // Set context
        $context = context_module::instance($cmid);

        xlog("Clearing any existing resource content for module ID {$cmid}: '{$resourcename}' of course ID {$courseid}: '{$coursename}'");
        $fs->delete_area_files($context->id, 'mod_resource', 'content');

        // Move into Moodle filesystem
        $file_record['contextid'] = $context->id;
        $file_record['filename'] = $filename;
        $file_record['itemid'] = '0';
        $file_record['timecreated'] = time();
        $file_record['timemodified'] = time();

        xlog("Migrating resource content file '{$filename}' into module ID {$cmid}: '{$resourcename}' of course ID {$courseid}: '{$coursename}'");
        $fs->create_file_from_pathname($file_record, $fileresourcefolder . $filename);

        // Update {resource} table where id = $resourceid
        // Retrieve the hash from the file
        $hash = $DB->get_field('files', 'contenthash',
                array('filename' => $filename, 'contextid' => $context->id));

        $resource = new stdClass();
        $resource->id = $resourceid;
        $resource->reference = $filename;
        $resource->md5hash = '';
        $resource->sha1hash = $hash;

        $DB->update_record('resource', $resource);

        // 2. The 'instance' field in the {course_modules} table of the record,
        // where ('id' => $resource->coursemodule) is set to the new record ID from step 1
        $DB->set_field('course_modules', 'instance', $resourceid, array('id' => $cmid));

        // Get the whole resource object data
        $resource = $DB->get_record('resource', array('id' => $resourceid));

        // Extra fields required in grade related functions.
        $resource->course     = $courseid;
        $resource->cmidnumber = '';
        $resource->cmid       = $cmid;

        xlog("Configuring resource module '{$resourcename}'");

        // Specific settings for the resource package
        $resourcesettings = new stdClass();
        $resourcesettings->id = $resourceid;
        // $resourcesettings->foo = 'bar';
        $DB->update_record('resource', $resourcesettings);

        xlog("Resource module '{$resourcename}' configured successfully");
    } else {

        $details = "File '{$filename}' intended for resource module '{$resourcename}' in course '{$coursename}' is missing";
        $notfound[] = $details;
        xlog($details);
    }
}

xlog('Scripting finished');
xlog(count($found) . ' files were found and processed successfully');
xlog(count($notfound) . ' files were not found');

function xlog($message) {
    global $reportfile;

    // Output to screen
    mtrace($message);

    // Write to report file
    fwrite($reportfile, $message . "\n");

}
// Close the report file
fclose($reportfile);
