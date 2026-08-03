<?php
declare(strict_types=1);

// Renders an already-generated word-search page's grid data (from its
// content_items text - "WORD LIST: ..." and "GRID ROW NN: ...") into an actual
// PNG image using GD, drawn entirely by code. AI image models can't reliably
// render an exact letter grid with words hidden at precise positions - asking
// one to "draw" the puzzle produces garbled, unplayable text. This renders the
// exact grid the app already computed, so the result is a real, correct,
// ready-to-use puzzle image instead of a prompt someone has to hand-assemble.

class BBWordSearchImageException extends Exception {}

function bb_parse_word_search_content_items(array $contentItems): array {
    $words = [];
    $rows = [];
    foreach ($contentItems as $item) {
        if (!is_string($item)) continue;
        if (preg_match('/^WORD\s*LIST:\s*(.+)$/i', $item, $m)) {
            $words = array_values(array_filter(array_map('trim', explode(',', $m[1])), fn($w) => $w !== ''));
        } elseif (preg_match('/^GRID\s*ROW\s*\d+:\s*(.+)$/i', $item, $m)) {
            $rows[] = preg_split('/\s+/', trim($m[1]));
        }
    }
    if (count($rows) === 0) {
        throw new BBWordSearchImageException('This page has no word-search grid data to render.');
    }
    return ['words' => $words, 'rows' => $rows];
}

// $transparent renders a see-through background (only the grid/letters/word
// list are opaque) so it can be dropped as its own layer on top of a
// separately AI-generated decorative background in Canva/Photoshop, instead
// of the solid white page bb_render_word_search_image() normally produces.
function bb_render_word_search_image(array $contentItems, string $title = 'Word Search', bool $transparent = false): string {
    if (!function_exists('imagecreatetruecolor')) {
        throw new BBWordSearchImageException('The GD image extension is not enabled on this server.');
    }
    ['words' => $words, 'rows' => $rows] = bb_parse_word_search_content_items($contentItems);
    $gridRows = count($rows);
    $gridCols = count($rows[0] ?? []);
    if ($gridCols === 0) throw new BBWordSearchImageException('This page\'s grid data is malformed.');

    $cell = 56;
    $margin = 44;
    $gridWidthPx = $gridCols * $cell;
    $gridHeightPx = $gridRows * $cell;
    $titleAreaHeight = 70;
    $wordListLineHeight = 26;
    $wordsPerLine = 4;
    $wordListLines = (int)ceil(count($words) / $wordsPerLine);
    $wordListAreaHeight = 50 + $wordListLines * $wordListLineHeight;

    $width = $gridWidthPx + $margin * 2;
    $height = $titleAreaHeight + $gridHeightPx + $wordListAreaHeight + $margin;

    $img = imagecreatetruecolor($width, $height);
    if ($transparent) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $bg = imagecolorallocatealpha($img, 255, 255, 255, 127);
        imagefill($img, 0, 0, $bg);
        imagealphablending($img, true);
    } else {
        $bg = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $bg);
    }
    $ink = imagecolorallocate($img, 25, 30, 35);
    $gridLine = imagecolorallocate($img, 190, 196, 202);

    $titleFont = 5;
    $titleText = strtoupper($title);
    $titleX = (int)max(0, ($width - strlen($titleText) * imagefontwidth($titleFont)) / 2);
    imagestring($img, $titleFont, $titleX, 18, $titleText, $ink);

    $gridTop = $titleAreaHeight;
    for ($r = 0; $r <= $gridRows; $r++) {
        imageline($img, $margin, $gridTop + $r * $cell, $margin + $gridWidthPx, $gridTop + $r * $cell, $gridLine);
    }
    for ($c = 0; $c <= $gridCols; $c++) {
        imageline($img, $margin + $c * $cell, $gridTop, $margin + $c * $cell, $gridTop + $gridHeightPx, $gridLine);
    }

    $letterFont = 5;
    $charW = imagefontwidth($letterFont);
    $charH = imagefontheight($letterFont);
    for ($r = 0; $r < $gridRows; $r++) {
        for ($c = 0; $c < count($rows[$r]); $c++) {
            $letter = substr((string)$rows[$r][$c], 0, 1);
            if ($letter === '') continue;
            $x = $margin + $c * $cell + (int)(($cell - $charW) / 2);
            $y = $gridTop + $r * $cell + (int)(($cell - $charH) / 2);
            imagestring($img, $letterFont, $x, $y, $letter, $ink);
        }
    }

    $listTop = $gridTop + $gridHeightPx + 30;
    imagestring($img, 4, $margin, $listTop, 'FIND THESE WORDS:', $ink);
    foreach (array_chunk($words, $wordsPerLine) as $i => $lineWords) {
        imagestring($img, 3, $margin, $listTop + 26 + $i * $wordListLineHeight, implode('     ', $lineWords), $ink);
    }

    ob_start();
    imagepng($img);
    $data = (string)ob_get_clean();
    imagedestroy($img);
    return $data;
}
