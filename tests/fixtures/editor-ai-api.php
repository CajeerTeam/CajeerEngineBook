<?php
if(($_SERVER['REQUEST_URI']??'')!=='/v1/responses'||($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(404);return;}
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer book-test-key-1234567890'){http_response_code(401);return;}
$data=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($data)||($data['store']??null)!==false||($data['stream']??null)!==true||($data['max_output_tokens']??0)>8192||!is_string($data['instructions']??null)){http_response_code(400);return;}
$prompt=(string)($data['input'][0]['content']??'');
if(str_contains($prompt,'simulate failure')){$result='error';}elseif(str_contains($prompt,'Return ONLY complete replacement Markdown'))$result="# Corrected\n\nCorrected setup instructions with commands.\n";
elseif(str_contains($prompt,'Identify likely documentation gaps'))$result='Prioritize short and unpublished pages.';
else $result='Check migration schema version; do not blindly rerun SQL.';
header('Content-Type: text/event-stream');
if($result==='error')echo 'data: '.json_encode(['type'=>'response.failed'])."\n\n";
else{
    $pieces=str_split($result,25);
    foreach($pieces as $chunk)echo 'data: '.json_encode(['type'=>'response.output_text.delta','delta'=>$chunk])."\n\n";
    echo 'data: '.json_encode(['type'=>'response.completed','response'=>['usage'=>['input_tokens'=>30,'output_tokens'=>20]]])."\n\n";
}
