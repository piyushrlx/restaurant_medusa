<?php
$file = __DIR__ . '/profile.php';
$content = file_get_contents($file);

$new_tab_start = strpos($content, '<!-- ══ TAB *: MEMBERSHIP PASS ══ -->');
$new_tab_end = strpos($content, '<?php endif; ?>', $new_tab_start);
if ($new_tab_start === false) die("1");
if ($new_tab_end === false) die("2");

$new_tab_content = substr($content, $new_tab_start, $new_tab_end - $new_tab_start);
$content = substr_replace($content, '', $new_tab_start, $new_tab_end - $new_tab_start);

$dummy_tab_start = strpos($content, '<!-- ══ TAB: MEMBERSHIP PASS ══ -->');
if ($dummy_tab_start === false) die("3");

$dummy_tab_end = strpos($content, '<!-- ══ TAB 4: ACCOUNT SETTINGS ══ -->', $dummy_tab_start);
if ($dummy_tab_end === false) die("4");

$content = substr_replace($content, $new_tab_content, $dummy_tab_start, $dummy_tab_end - $dummy_tab_start);

if (file_put_contents($file, $content)) {
    echo "Fixed successfully!";
} else {
    echo "Error writing file";
}
