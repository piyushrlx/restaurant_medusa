<?php
$file = __DIR__ . '/profile.php';
$content = file_get_contents($file);

$new_tab_start = strpos($content, '<!-- ══ TAB *: MEMBERSHIP PASS ══ -->');
$new_tab_end = strpos($content, '<?php endif; ', $new_tab_start);
if ($new_tab_start === false || $new_tab_end === false) {
    die("Could not find the new tab boundaries.");
}

$new_tab_content = substr($content, $new_tab_start, $new_tab_end - $new_tab_start);
$content = substr_replace($content, '', $new_tab_start, $new_tab_end - $new_tab_start);

$dummy_tab_start = strpos($content, '<!-- ══ TAB: MEMBERSHIP PASS ══ -->');
$dummy_tab_end = strpos($content, '<!-- ══ TAB 4: ACCOUNT SETTINGS ══ -->', $dummy_tab_start);

if ($dummy_tab_start === false || $dummy_tab_end === false) {
    die("Could not find dummy tab boundaries.");
}

$content = substr_replace($content, $new_tab_content, $dummy_tab_start, $dummy_tab_end - $dummy_tab_start);

if (file_put_contents($file, $content)) {
    echo "<h1>Fixed successfully!</h1><p>The profile tabs have been corrected.</p>";
} else {
    echo "<h1>Error</h1><p>Failed to write to profile.php</p>";
}
