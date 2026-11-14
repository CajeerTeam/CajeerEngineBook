<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(in_array($path,['/assets/book.css','/assets/book.js','/assets/studio-editor.js','/assets/media.js','/assets/studio-media.js','/assets/page-tree.js'],true))return false;
require __DIR__.'/index.php';
