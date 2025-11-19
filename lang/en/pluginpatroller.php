<?php
defined('MOODLE_INTERNAL') || die();

// Plugin general
$string['pluginname'] = 'GitPatroller';
$string['modulename'] = 'GitPatroller';
$string['modulenameplural'] = 'pluginpatroller';
$string['pluginpatroller:addinstance'] = 'Add a new plugin instance';
$string['pluginpatroller:view'] = 'View GitPatroller';
$string['pluginpatroller'] = 'pluginpatroller';
$string['pluginadministration'] = 'pluginpatroller administration';

// Configuration
$string['patrollerheader'] = 'Patroller Config';
$string['name'] = 'Name';
$string['max_users_per_group'] = 'Maximum users per group (suggested)';
$string['tokenpatroller'] = 'Github Token';
$string['tokenpatroller_desc'] = 'Github Bot Token';
$string['ownerpatroller'] = 'Github Owner';
$string['ownerpatroller_desc'] = 'Github Bot Username';
$string['filterbysede'] = 'Campus';
$string['filterbycurso'] = 'Course';
$string['filterbyrepo'] = 'Repository';

// AI Settings
$string['ai_settings'] = 'AI Configuration';
$string['ai_settings_desc'] = 'Configuration settings for AI-powered insights';
$string['gemini_api_key'] = 'Google Gemini API Key';
$string['gemini_api_key_desc'] = 'API key for accessing Google Gemini AI services';

// AI Insights UI
$string['tabai'] = 'AI Insights';
$string['ai_header_title'] = 'AI Analysis Dashboard';
$string['ai_header_subtitle'] = 'AI Demo - Commits analysis';
$string['ai_demo_message'] = 'Quick demo: select a student and click "Analyze" to run an AI-powered analysis.';
$string['ai_label_select_student'] = 'Select Student';
$string['ai_label_repository'] = 'Repository';
$string['ai_label_action'] = 'Action';
$string['ai_label_analyze_button'] = 'Analyze';
$string['ai_label_no_github'] = 'No GitHub';
$string['ai_no_students'] = 'No students with repositories configured for analysis.';
$string['ai_select_prompt_title'] = 'Select a student for analysis';
$string['ai_select_prompt_paragraph'] = 'Choose a student from the table on the left and click "Analyze" to see an AI-generated report of their progress.';

// AI Analysis Results
$string['ai_analysis_results_title'] = 'AI Analysis Results';
$string['ai_analysis_for'] = 'Analysis for';
$string['ai_repository_info_title'] = 'Repository Information';
$string['ai_repository_label'] = 'Repository:';
$string['ai_last_commit_analyzed'] = 'Last Commit Analyzed:';
$string['ai_commits_label'] = 'Commits';
$string['ai_lines_added_label'] = 'Lines added';
$string['ai_lines_deleted_label'] = 'Lines deleted';
$string['ai_development_description_title'] = 'Development Description:';
$string['ai_suggestions_title'] = 'Suggestions for Improvement:';
$string['ai_analyzing_button'] = 'Analyzing...';
// AI analysis success message
$string['ai_success_analysis_completed'] = '✅ Analysis completed for student ID: {$a}';

// Statistics
$string['statistics'] = 'Statistics';
$string['statisticsheader'] = 'Statistics - Admin Template';
$string['globalstatistics'] = 'Global Statistics';
$string['groupstatistics'] = 'Group Statistics';
$string['selectgroup'] = 'Select Group:';
$string['commits'] = 'Commits';
$string['lines'] = 'Lines';
$string['commitspergroup'] = 'Commits per Group';
$string['commitsperstudent'] = 'Commits per Student';
$string['totallines'] = 'Total Lines';
$string['metricglobal'] = 'Global Metric';
$string['metricgroup'] = 'Group Metric';
$string['contributionscomparison'] = 'Contributions comparison by group and students';

// Main Panel
$string['mainpanel'] = 'Main Panel';
$string['dashboard'] = 'Dashboard';
$string['overview'] = 'Overview';
$string['totalstudents'] = 'Total Students';
$string['totalgroups'] = 'Total Groups';
$string['activerepos'] = 'Active Repositories';
$string['lastactivity'] = 'Last Activity';

// Student View
$string['studentview'] = 'Student View';
$string['myrepo'] = 'My Repository';
$string['mygroup'] = 'My Group';
$string['mystats'] = 'My Statistics';
$string['groupmates'] = 'Teammates';
$string['repolink'] = 'Repository Link';
$string['githubusername'] = 'GitHub Username';
$string['selectrepo'] = 'Select Repository';
$string['savechanges'] = 'Save Changes';

// Group Management
$string['groupmanagement'] = 'Group Management';
$string['creategroup'] = 'Create Group';
$string['editgroup'] = 'Edit Group';
$string['deletegroup'] = 'Delete Group';
$string['groupname'] = 'Group Name';
$string['groupmembers'] = 'Group Members';
$string['addmember'] = 'Add Member';
$string['removemember'] = 'Remove Member';

// Access Management
$string['accessmanagement'] = 'Access Management';
$string['gestionaccesos'] = 'Access Management';
$string['invitations'] = 'Invitations';
$string['sendinvitation'] = 'Send Invitation';
$string['pendinginvitations'] = 'Pending Invitations';
$string['acceptedinvitations'] = 'Accepted Invitations';
$string['unregisteredusers'] = 'Unregistered Users';

// Contributors Insights
$string['contributorsinsights'] = 'Contributors Insights';
$string['topcontributors'] = 'Top Contributors';
$string['recentactivity'] = 'Recent Activity';
$string['codefrequency'] = 'Code Frequency';
$string['weeklycommits'] = 'Weekly Commits';

// Repository Management
$string['repositorymanagement'] = 'Repository Management';
$string['githubreposadmin'] = 'GitHub repository administration';
$string['pluginnotconfigured'] = 'GitPatroller plugin not configured. Configure token and GitHub organization at: Site administration → Plugins → Activity modules → PatrollerPRF';
$string['createrepo'] = 'Create Repository';
$string['editrepo'] = 'Edit Repository';
$string['deleterepo'] = 'Delete Repository';
$string['deleterepository'] = 'Delete Repository';
$string['confirmdeleterepository'] = 'Are you sure you want to delete this repository? This action cannot be undone.';
$string['reponame'] = 'Repository Name';
$string['repodescription'] = 'Repository Description';
$string['repoisactive'] = 'Repository is Active';

// Filters
$string['filters'] = 'Filters';
$string['advancedfilters'] = 'Advanced Filters';
$string['applyfilters'] = 'Apply Filters';
$string['clearfilters'] = 'Clear Filters';
$string['filterresults'] = 'Filter Results';

// Common actions
$string['save'] = 'Save';
$string['cancel'] = 'Cancel';
$string['edit'] = 'Edit';
$string['delete'] = 'Delete';
$string['view'] = 'View';
$string['back'] = 'Back';
$string['next'] = 'Next';
$string['previous'] = 'Previous';
$string['loading'] = 'Loading...';
$string['error'] = 'Error';
$string['success'] = 'Success';
$string['warning'] = 'Warning';
$string['info'] = 'Information';

// Messages
$string['nostudentstodisplay'] = 'No students to display';
$string['norepositories'] = 'No repositories found';
$string['nodata'] = 'No data available';
$string['errorrenderingstats'] = 'Error rendering statistics';
$string['dataloadedsuccessfully'] = 'Data loaded successfully';

// Group labels
$string['group'] = 'Group';
$string['students'] = 'students';
$string['group_header_subtitle'] = 'Detailed information about your study group';

// Error messages
$string['problemoccurred'] = 'A problem has occurred';
$string['errordetails'] = 'Error details:';
$string['whatcanyoudo'] = 'What can you do?';
$string['refreshpage'] = 'Refresh the page and try again';
$string['checkpermissions'] = 'Verify that you have the necessary permissions';
$string['contactadmin'] = 'Contact the administrator if it persists';
$string['technicalinfo'] = 'Technical information:';
$string['course'] = 'Course:';
$string['user'] = 'User:';
$string['timestamp'] = 'Timestamp:';
$string['goback'] = 'Go Back';
$string['reloadpage'] = 'Reload Page';

// Alert messages
$string['success'] = 'Success!';
$string['error'] = 'Error:';
$string['information'] = 'Information:';
$string['warning'] = 'Warning:';
$string['close'] = 'Close';

// Filters
$string['filters'] = 'Filters';
$string['clear'] = 'Clear';

// Access Management
$string['accessmanagement'] = 'GitHub Access Management';
$string['manageinvitationsrepos'] = 'Manage invitations and repository assignments';
$string['githubprocessing'] = 'GitHub Invitation Processing';
$string['invitationsregisters'] = 'GitHub Invitations and Records';
$string['selectrepository'] = 'Select Repository:';
$string['sendinvitations'] = 'Send Invitations';
$string['savechanges'] = 'Save Changes';
$string['user'] = 'User';
$string['sede'] = 'Campus';
$string['repository'] = 'Repository';
$string['invitationstatus'] = 'Invitation Status';
$string['actions'] = 'Actions';
$string['ready'] = 'Ready';
$string['pending'] = 'Pending';
$string['resetstatus'] = 'Reset Status';
$string['nostudents'] = 'No students:';
$string['nostudentsregistered'] = 'No students registered in this course.';
$string['invitationsnote'] = 'Only invitations will be sent to students with assigned repository and configured GitHub user.';
$string['resetinvitations'] = 'Reset Invitation Status';
$string['resetinvitationsdesc'] = 'Changes all invitation status to "Unprocessed" to retry sending invitations.';
$string['resetconfirm'] = 'Are you sure you want to reset all invitation status?';
$string['select'] = 'Select...';
$string['githubplaceholder'] = 'github_user';
$string['note'] = 'Note';
$string['allrepositories'] = 'All Repositories';
$string['invitationsreset'] = 'Invitation status reset successfully.';
$string['changessaved'] = 'Changes saved successfully.';

// Invitation status labels
$string['status_unprocessed'] = 'Unprocessed';
$string['status_missing_username'] = 'Missing username';
$string['status_username_not_found'] = 'Username not found';
$string['status_sent'] = 'Invitation sent';
$string['status_error_sending'] = 'Error sending';
$string['status_accepted'] = 'Accepted';
$string['status_pending'] = 'Pending';
$string['status_sent_legacy'] = 'Sent (legacy)';
$string['status_error'] = 'Error';
$string['status_unknown'] = 'Unknown status';

// Main panel
$string['courseinfo'] = 'Course Information';
$string['subject'] = 'Subject';
$string['year'] = 'Year';
$string['semester'] = 'Semester';
$string['totalpending'] = 'Total Pending';
$string['maxstudentsperrepo'] = 'Max. Students / Repo';
$string['pendingstudentsbyseatcourse'] = 'Pending students by Campus and Course';
$string['pending'] = 'pending';
$string['nopending'] = 'No pending';
$string['createrepositories'] = 'Create Repositories';
$string['repos'] = 'repos';

// Main panel additional labels
$string['seatcourse'] = 'Campus \ Course';
$string['createdrepos'] = 'Created Repositories';
$string['groupnumber'] = 'Group #';
$string['members'] = 'Members';
$string['members_title'] = 'Number of members in the group';
$string['active'] = 'Active';
$string['active_title'] = 'Students who already accepted the invitation';
$string['pending_title'] = 'Students invited but not yet accepted';
$string['notfound'] = 'Not found';
$string['notfound_title'] = 'Students whose GitHub username was not found';
$string['missingusername'] = 'Missing username';
$string['missingusername_title'] = 'Students without GitHub username configured';
$string['unprocessed_title'] = 'Students not processed';
$string['error_title'] = 'Students processed with error';

// No repositories section
$string['noreposfound'] = 'No repositories created yet';
$string['noreposfounddesc'] = 'When you create repositories, they will appear here with their detailed statistics.';

// Repo creation options
$string['repo_to_add_singular'] = '1 repository to add';
$string['repo_to_add_plural'] = '{$a} repositories to add';

// Unregistered users
$string['unregisteredusers'] = 'Unregistered Users';
$string['enrolledstudentspending'] = 'Enrolled students pending registration in GitPatroller';
$string['searchfilters'] = 'Search Filters';
$string['clearfilters'] = 'Clear Filters';
$string['studentsunregistered'] = 'unregistered students';
$string['virtualclassroomuser'] = 'Virtual Classroom User';
$string['fullname'] = 'Full Name';
$string['email'] = 'Email';
$string['unassigned'] = 'Unassigned';
$string['unregisterednote'] = 'These students are enrolled but have not yet accessed the GitPatroller system to register their GitHub information.';
$string['excellent'] = 'Excellent!';
$string['allstudentsregistered'] = 'All enrolled students have already registered in the GitPatroller system. There are no users pending registration.';

// Filter labels
$string['searchbyname'] = 'Search by Name';
$string['filterbysede'] = 'Filter by Campus';
$string['filterbycourse'] = 'Filter by Course';
$string['filterbyrepository'] = 'Filter by Repository';
$string['searchplaceholder'] = 'Type to search...';
$string['allcourses'] = 'All Courses';
$string['allrepositories'] = 'All Repositories';
$string['allgroups'] = 'All Groups';
$string['allsedes'] = 'All Campuses';
$string['all'] = 'All';
$string['showingxofy'] = 'Showing {$a->visible} of {$a->total} records';

// Contributors insights
$string['trackingandgrading'] = 'Tracking and Grading';
$string['contributionsmonitoring'] = 'Contributions monitoring and student evaluation';
$string['searchfilters'] = 'Search Filters';
$string['clearfilters'] = 'Clear Filters';
$string['updatecommitdata'] = 'Update Commit Data';
$string['contributionstracking'] = 'Contributions and Grading Tracking';
$string['repository'] = 'Repository';
$string['githubuser'] = 'GitHub User';
$string['fullname'] = 'Full Name';
$string['lastcommit'] = 'Last Commit';
$string['commits'] = 'Commits';
$string['linesadded'] = 'Lines added';

// Teacher repos
$string['githubuser'] = 'GitHub User';
$string['githubusername'] = 'GitHub username:';
$string['save'] = 'Save';
$string['userconfiguredcorrectly'] = 'User configured correctly';
$string['configuregithubuser'] = 'Configure your GitHub user to be able to assign repositories';
$string['myassignedrepos'] = 'My Assigned Repositories';
$string['synchronizestatus'] = 'Synchronize Status';
$string['invitationstatus'] = 'Invitation Status';
$string['actions'] = 'Actions';
$string['availablerepos'] = 'Available Repositories for Assignment';
$string['assignselectedrepos'] = 'Assign Selected Repositories';
$string['reposassignedtoothers'] = 'Repositories Assigned to Other Teachers';
$string['noreposavailable'] = 'No repositories available in this course';
$string['attention'] = 'Attention:';
$string['changeusernamewarning'] = 'If you change your GitHub username, you will be removed as collaborator from all your assigned repositories';
$string['noassignedrepos'] = 'You have no assigned repositories yet.';
$string['canassignfrom'] = 'You can assign repositories from the "Available Repositories" section below.';
$string['configurefirst'] = 'You must configure your GitHub username before being able to assign repositories.';
$string['assignlimit'] = 'Limit: You can assign up to 10 repositories per operation.';
$string['selectrepos'] = 'Select the repositories you want to assign.';

// Missing string definitions
$string['linesdeleted'] = 'Lines deleted';
$string['linesmodified'] = 'Lines modified';
$string['grade'] = 'Grade';
$string['nograde'] = 'No grade';
$string['savegrades'] = 'Save Grades';
$string['never'] = 'Never';
$string['notconfigured'] = 'Not configured';

// Additional teacher repos strings
$string['confirmunassign'] = 'Are you sure you want to unassign this repository?\nThis will remove your access on GitHub.';
$string['unassign'] = 'Unassign';
$string['unassignrepo'] = 'Unassign repository';
$string['noactions'] = 'No actions';
$string['note'] = 'Note:';
$string['invitationinfo'] = 'After assigning a repository, you will receive an invitation on GitHub that you must accept to have access.';
$string['usesyncbutton'] = 'Use the "Synchronize Status" button to update the status of your invitations.';
$string['selected'] = 'selected';
$string['mustconfiguregithub'] = 'You must configure your GitHub username before being able to assign repositories.';
$string['selectdeselectall'] = 'Select/Deselect all';
$string['configureuserfirst'] = 'Configure your GitHub user first';

// Tab names
$string['tabrepositories'] = 'Repositories';
$string['tabparticipants'] = 'Participants';
$string['tabmygroup'] = 'My Group';
$string['tabaccessmanagement'] = 'Access Management';
$string['tabtrackinggrading'] = 'Tracking and Grading';
$string['tabstatistics'] = 'Statistics';
$string['tabunregistered'] = 'Unregistered';
$string['tabteacherrepos'] = 'Teacher Repositories';
$string['tabai'] = 'AI Insights';

// Error messages
$string['unknowntab'] = 'Unknown tab.';
$string['erroroccurred'] = 'An error has occurred';
$string['limit'] = 'Limit:';
$string['upto10repos'] = 'You can assign up to 10 repositories per operation.';
$string['selectrepostoassign'] = 'Select the repositories you want to assign.';
$string['selectatleastone'] = 'Select at least one repository';
$string['state'] = 'State';

// Modal and additional strings
$string['confirmationchangemodal'] = 'GitHub User Change Confirmation';
$string['confirmationchange'] = 'Change Confirmation';
$string['currentuser'] = 'Current user:';
$string['newuser'] = 'New user:';
$string['changeconsequences'] = 'Change consequences:';
$string['willberemoved'] = 'You will be removed as collaborator from all your assigned repositories on GitHub';
$string['willloseaccess'] = 'You will lose immediate access to';
$string['repositories'] = 'repository(s)';
$string['mustreassign'] = 'You must manually reassign the repositories you need with the new user';
$string['invitationscanceled'] = 'Previous invitations will be canceled';
$string['cannotrecover'] = 'If you change the username by mistake, you cannot automatically recover your previous assignments.';
$string['confirmchange'] = 'Confirm Change';
$string['cancel'] = 'Cancel';

// Student view strings
$string['classmates'] = 'Classmates';
$string['academicgroupinfo'] = 'View and manage your academic group information';
$string['youracademiccontext'] = 'Your academic context:';
$string['classmateslist'] = 'Classmates List';
$string['campusandcourse'] = 'Campus and Course';
$string['repositorygroup'] = 'Repository/Group';
$string['yourgithubusername'] = 'Your GitHub username';
$string['lockedstate'] = 'Locked state';
$string['noreposavailable'] = 'No repositories available';
$string['save'] = 'Save';
$string['noteditable'] = 'Not editable';
$string['noclassmatesregistered'] = 'No classmates registered';
$string['noclassmatesexplanation'] = 'No other students were found registered at your campus and course. They may not have registered in the system yet or you may be the only enrolled student.';
$string['noclassmatesfound'] = 'No other students registered in your campus and course were found. They may not have registered in the system yet or you may be the only enrolled student.';

// Missing strings from previous work
$string['nostudentswithrepos'] = 'No students with assigned repositories';
$string['assignreposfirst'] = 'First assign repositories to students in the';
$string['accessmanagement'] = 'Access Management';
$string['unassigned'] = 'Unassigned';
$string['never'] = 'Never';
$string['notconfigured'] = 'Not configured';
$string['selected'] = 'selected';
$string['mustconfiguregithub'] = 'You must configure your GitHub username before being able to assign repositories.';
$string['configureuserfirst'] = 'Configure your GitHub user first';




$string['nostudentswithrepos'] = 'No students with assigned repositories';
$string['assignreposfirst'] = 'First assign repositories to students in the';
$string['accessmanagement'] = 'Access Management';
$string['unassigned'] = 'Unassigned';
$string['never'] = 'Never';
$string['notconfigured'] = 'Not configured';

// Group view
$string['success'] = 'Success!';
$string['error'] = 'Error:';
$string['close'] = 'Close';
$string['group'] = 'Group';
$string['groupinformation'] = 'Group Information';
$string['campusandcourse'] = 'Campus and Course';
$string['totalmembers'] = 'Total Members';
$string['students'] = 'students';
$string['name'] = 'Name';
$string['you'] = '(You)';
$string['statistics'] = 'Statistics';
$string['metric'] = 'Metric';

// Repository management
$string['repositoriescreatedsuccessfully'] = 'Repositories created successfully';

// Sync button tooltips
$string['detectchangesinvitations'] = 'Detect changes in invitations';

// Sync messages for JavaScript
$string['syncing'] = 'Synchronizing...';
$string['syncsuccess'] = '✅ States synchronized successfully!';
$string['errorserver'] = 'Server response error';
$string['errorsync'] = 'Error synchronizing';
$string['synccompleted'] = 'Synchronization completed!';

// Invitation states
$string['unverified'] = 'Unverified';
$string['incomplete'] = 'Incomplete';

// Action buttons
$string['change'] = 'Change';
$string['changerepository'] = 'Change repository';
$string['changerepositoryfor'] = 'Change Repository for';
$string['send'] = 'Send';
$string['sendinvitation'] = 'Send invitation';
$string['sendinvitationcollaboration'] = 'Send collaboration invitation';
$string['cancelinvitation'] = 'Cancel invitation';
$string['cancelinvitationpending'] = 'Cancel pending invitation';

// Confirmation messages
$string['confirmcancelinvitation'] = 'Are you sure you want to cancel the invitation for {$a}?';
$string['confirmsendinvitation'] = 'Send collaboration invitation to {$a}?';

// Change repository form
$string['student'] = 'Student';
$string['githubuser'] = 'GitHub User';
$string['currentrepository'] = 'Current Repository';
$string['newrepository'] = 'New Repository';
$string['selectrepository'] = 'Select a repository';
$string['confirmchange'] = 'Confirm Change';

// Invitation status states
$string['collaboratoractive'] = 'Active Collaborator';
$string['invitationpending'] = 'Invitation Pending';
$string['invitationexpired'] = 'Invitation Expired';
$string['noinvitation'] = 'No Invitation';

?>

