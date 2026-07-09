<?php
// Rocket.Chat panel landing page (for the top navbar drawer).
//
// @package   block_rocketchat

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/rocketchat/view.php'));
$PAGE->set_pagelayout('embedded'); // important: looks good inside iframe
$PAGE->set_title('Rocket.Chat');
$PAGE->set_heading('Rocket.Chat');

echo $OUTPUT->header();
echo block_rocketchat_render_panel($PAGE, 0);
echo $OUTPUT->footer();
