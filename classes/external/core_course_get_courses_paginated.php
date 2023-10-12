<?php

namespace local_aspiredu\external;

use context_course;
use core_course\customfield\course_handler;
use Exception;
use external_api;
use external_description;
use external_format_value;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use external_warnings;
use invalid_parameter_exception;
use moodle_exception;
use stdClass;

global $CFG;
require_once("$CFG->dirroot/course/externallib.php");

class core_course_get_courses_paginated extends external_api {
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 3.0
     */
    public static function execute_parameters() {
        return new external_function_parameters (
            [
                'sortby' => new external_value(PARAM_ALPHA,
                    'sort by this element: id, timemodified, timestart or timeend', VALUE_DEFAULT, 'timemodified'),
                'sortdirection' => new external_value(PARAM_ALPHA, 'sort direction: ASC or DESC', VALUE_DEFAULT, 'DESC'),
                'page' => new external_value(PARAM_INT, 'current page', VALUE_DEFAULT, -1),
                'perpage' => new external_value(PARAM_INT, 'items per page', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Return information about a course module.
     *
     * @param int $module the module name
     * @param int $instance the module instance
     * @return array of warnings and the course module
     * @throws moodle_exception
     * @since Moodle 3.0
     */
    public static function execute($sortby = 'id',
                                   $sortdirection = 'DESC', $page = -1, $perpage = 0) {

        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $warnings = [];

        $params = self::validate_parameters(self::execute_parameters(),
            [
                'sortby' => $sortby,
                'sortdirection' => $sortdirection,
                'page' => $page,
                'perpage' => $perpage
            ]
        );

        $sortby = $params['sortby'];
        $sortdirection = $params['sortdirection'];
        $page = $params['page'];
        $perpage = $params['perpage'];

        $sortallowedvalues = ['id', 'startdate', 'enddate', 'timemodified'];
        if (!in_array($sortby, $sortallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortby parameter (value: ' . $sortby . '),' .
                'allowed values are: ' . implode(',', $sortallowedvalues));
        }

        $sortdirection = strtoupper($sortdirection);
        $directionallowedvalues = ['ASC', 'DESC'];
        if (!in_array($sortdirection, $directionallowedvalues)) {
            throw new invalid_parameter_exception('Invalid value for sortdirection parameter (value: ' . $sortdirection . '),' .
                'allowed values are: ' . implode(',', $directionallowedvalues));
        }

        if ($page != -1) {
            $limitfrom = $page * $perpage;
            $limitnum = $perpage;
        } else {
            $limitfrom = 0;
            $limitnum = 0;
        }
        $sort = $sortby . ' ' . $sortdirection;

        $courses = $DB->get_records('course', null, $sort, '*', $limitfrom, $limitnum);

        $coursesinfo = [];
        foreach ($courses as $course) {
            // Now security checks.
            $context = context_course::instance($course->id, IGNORE_MISSING);
            $courseformatoptions = course_get_format($course)->get_format_options();
            try {
                self::validate_context($context);
            } catch (Exception $e) {
                $exceptionparam = new stdClass();
                $exceptionparam->message = $e->getMessage();
                $exceptionparam->courseid = $course->id;
                throw new moodle_exception('errorcoursecontextnotvalid', 'webservice', '', $exceptionparam);
            }
            if ($course->id != SITEID) {
                require_capability('moodle/course:view', $context);
            }

            $courseinfo = [];
            $courseinfo['id'] = $course->id;
            $courseinfo['fullname'] = external_format_string($course->fullname, $context->id);
            $courseinfo['shortname'] = external_format_string($course->shortname, $context->id);
            $courseinfo['displayname'] = external_format_string(get_course_display_name_for_list($course), $context->id);
            $courseinfo['categoryid'] = $course->category;
            list($courseinfo['summary'], $courseinfo['summaryformat']) =
                external_format_text($course->summary, $course->summaryformat, $context->id, 'course', 'summary', 0);
            $courseinfo['format'] = $course->format;
            $courseinfo['startdate'] = $course->startdate;
            $courseinfo['enddate'] = $course->enddate;
            $courseinfo['showactivitydates'] = $course->showactivitydates;
            $courseinfo['showcompletionconditions'] = $course->showcompletionconditions;
            if (array_key_exists('numsections', $courseformatoptions)) {
                // For backward-compatibility.
                $courseinfo['numsections'] = $courseformatoptions['numsections'];
            }

            $handler = course_handler::create();
            if ($customfields = $handler->export_instance_data($course->id)) {
                $courseinfo['customfields'] = [];
                foreach ($customfields as $data) {
                    $courseinfo['customfields'][] = [
                        'type' => $data->get_type(),
                        'value' => $data->get_value(),
                        'valueraw' => $data->get_data_controller()->get_value(),
                        'name' => $data->get_name(),
                        'shortname' => $data->get_shortname()
                    ];
                }
            }

            // Some fields should be returned only if the user has update permission.
            $courseadmin = has_capability('moodle/course:update', $context);
            if ($courseadmin) {
                $courseinfo['categorysortorder'] = $course->sortorder;
                $courseinfo['idnumber'] = $course->idnumber;
                $courseinfo['showgrades'] = $course->showgrades;
                $courseinfo['showreports'] = $course->showreports;
                $courseinfo['newsitems'] = $course->newsitems;
                $courseinfo['visible'] = $course->visible;
                $courseinfo['maxbytes'] = $course->maxbytes;
                if (array_key_exists('hiddensections', $courseformatoptions)) {
                    // For backward-compatibility.
                    $courseinfo['hiddensections'] = $courseformatoptions['hiddensections'];
                }
                // Return numsections for backward-compatibility with clients who expect it.
                $courseinfo['numsections'] = course_get_format($course)->get_last_section_number();
                $courseinfo['groupmode'] = $course->groupmode;
                $courseinfo['groupmodeforce'] = $course->groupmodeforce;
                $courseinfo['defaultgroupingid'] = $course->defaultgroupingid;
                $courseinfo['lang'] = clean_param($course->lang, PARAM_LANG);
                $courseinfo['timecreated'] = $course->timecreated;
                $courseinfo['timemodified'] = $course->timemodified;
                $courseinfo['forcetheme'] = clean_param($course->theme, PARAM_THEME);
                $courseinfo['enablecompletion'] = $course->enablecompletion;
                $courseinfo['completionnotify'] = $course->completionnotify;
                $courseinfo['courseformatoptions'] = [];
                foreach ($courseformatoptions as $key => $value) {
                    $courseinfo['courseformatoptions'][] = [
                        'name' => $key,
                        'value' => $value
                    ];
                }
            }

            if ($courseadmin || $course->visible
                || has_capability('moodle/course:viewhiddencourses', $context)) {
                $coursesinfo[] = $courseinfo;
            }
        }

        $result = [];
        $result['courses'] = $coursesinfo;
        $result['warnings'] = $warnings;
        return $result;

    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function execute_returns() {

        return new external_single_structure(
            [
                'courses' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_INT, 'course id'),
                            'shortname' => new external_value(PARAM_RAW, 'course short name'),
                            'categoryid' => new external_value(PARAM_INT, 'category id'),
                            'categorysortorder' => new external_value(PARAM_INT,
                                'sort order into the category', VALUE_OPTIONAL),
                            'fullname' => new external_value(PARAM_RAW, 'full name'),
                            'displayname' => new external_value(PARAM_RAW, 'course display name'),
                            'idnumber' => new external_value(PARAM_RAW, 'id number', VALUE_OPTIONAL),
                            'summary' => new external_value(PARAM_RAW, 'summary'),
                            'summaryformat' => new external_format_value('summary'),
                            'format' => new external_value(PARAM_PLUGIN,
                                'course format: weeks, topics, social, site,..'),
                            'showgrades' => new external_value(PARAM_INT,
                                '1 if grades are shown, otherwise 0', VALUE_OPTIONAL),
                            'newsitems' => new external_value(PARAM_INT,
                                'number of recent items appearing on the course page', VALUE_OPTIONAL),
                            'startdate' => new external_value(PARAM_INT,
                                'timestamp when the course start'),
                            'enddate' => new external_value(PARAM_INT,
                                'timestamp when the course end'),
                            'numsections' => new external_value(PARAM_INT,
                                '(deprecated, use courseformatoptions) number of weeks/topics',
                                VALUE_OPTIONAL),
                            'maxbytes' => new external_value(PARAM_INT,
                                'largest size of file that can be uploaded into the course',
                                VALUE_OPTIONAL),
                            'showreports' => new external_value(PARAM_INT,
                                'are activity report shown (yes = 1, no =0)', VALUE_OPTIONAL),
                            'visible' => new external_value(PARAM_INT,
                                '1: available to student, 0:not available', VALUE_OPTIONAL),
                            'hiddensections' => new external_value(PARAM_INT,
                                '(deprecated, use courseformatoptions) How the hidden
                                        sections in the course are displayed to students',
                                VALUE_OPTIONAL),
                            'groupmode' => new external_value(PARAM_INT, 'no group, separate, visible',
                                VALUE_OPTIONAL),
                            'groupmodeforce' => new external_value(PARAM_INT, '1: yes, 0: no',
                                VALUE_OPTIONAL),
                            'defaultgroupingid' => new external_value(PARAM_INT, 'default grouping id',
                                VALUE_OPTIONAL),
                            'timecreated' => new external_value(PARAM_INT,
                                'timestamp when the course have been created', VALUE_OPTIONAL),
                            'timemodified' => new external_value(PARAM_INT,
                                'timestamp when the course have been modified', VALUE_OPTIONAL),
                            'enablecompletion' => new external_value(PARAM_INT,
                                'Enabled, control via completion and activity settings. Disbaled,
                                            not shown in activity settings.',
                                VALUE_OPTIONAL),
                            'completionnotify' => new external_value(PARAM_INT,
                                '1: yes 0: no', VALUE_OPTIONAL),
                            'lang' => new external_value(PARAM_SAFEDIR,
                                'forced course language', VALUE_OPTIONAL),
                            'forcetheme' => new external_value(PARAM_PLUGIN,
                                'name of the force theme', VALUE_OPTIONAL),
                            'courseformatoptions' => new external_multiple_structure(
                                new external_single_structure(
                                    ['name' => new external_value(PARAM_ALPHANUMEXT, 'course format option name'),
                                        'value' => new external_value(PARAM_RAW, 'course format option value')
                                    ]), 'additional options for particular course format', VALUE_OPTIONAL
                            ),
                            'showactivitydates' => new external_value(
                                PARAM_BOOL, 'Whether the activity dates are shown or not'),
                            'showcompletionconditions' => new external_value(PARAM_BOOL,
                                'Whether the activity completion conditions are shown or not'),
                            'customfields' => new external_multiple_structure(
                                new external_single_structure(
                                    ['name' => new external_value(PARAM_RAW, 'The name of the custom field'),
                                        'shortname' => new external_value(PARAM_ALPHANUMEXT, 'The shortname of the custom field'),
                                        'type' => new external_value(PARAM_COMPONENT,
                                            'The type of the custom field - text, checkbox...'),
                                        'valueraw' => new external_value(PARAM_RAW, 'The raw value of the custom field'),
                                        'value' => new external_value(PARAM_RAW, 'The value of the custom field')]
                                ), 'Custom fields and associated values', VALUE_OPTIONAL),
                        ], 'course'
                    )
                ),
                'warnings' => new external_warnings()
            ]
        );
    }
}