<?php
declare(strict_types=1);
use CajeerBook\{DiagramFrame, Markdown, StudioEditor};
$policy = in_array('--policy-only', $argv, true);
if ($policy) {
    require dirname(__DIR__).'/src/DiagramFrame.php';
    require dirname(__DIR__).'/src/Markdown.php';
    require dirname(__DIR__).'/src/StudioEditor.php';
} else require dirname(__DIR__).'/bootstrap.php';
function check(bool $ok, string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
ob_start(); DiagramFrame::serve('GET'); $frame=ob_get_clean();
check(str_contains($frame,'/assets/mermaid/mermaid.min.js') && str_contains($frame,'/assets/diagram-frame.js'),'local frame scripts only');
check(!str_contains($frame,'https://') && !str_contains($frame,'<iframe'),'no CDN or nested frame');
$markup=StudioEditor::markup('');
check(str_contains($markup,'value="mermaid"'),'Studio Mermaid block selection');
if ($policy) exit;
$source="# Example\n\n```mermaid\nflowchart LR\n A[Start] --> B[Finish]\n```\n\n```text\n<script>alert(1)</script>\n```\n";
$r=Markdown::render($source,'qa/1.0/diagram',true,true);
check(substr_count($r['html'],'data-book-mermaid="true"')===1,'only Mermaid fence enhanced');
check(str_contains($r['html'],'A[Start] --&gt; B[Finish]') && !str_contains($r['html'],'<script>'),'diagram source remains escaped and readable');
check(implode('',array_column($r['blocks'],'source'))===$source,'lossless AST source');
check(in_array('code',array_column($r['blocks'],'kind'),true),'Mermaid reuses code block editor');
$r=Markdown::render("```mermaid extra\nflowchart LR\nA-->B\n```\n");
check(!str_contains($r['html'],'data-book-mermaid'),'nonstandard info remains code');
