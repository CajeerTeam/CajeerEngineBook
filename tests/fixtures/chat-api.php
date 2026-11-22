<?php
if(($_SERVER['REQUEST_URI']??'')!=='/v1/responses'||($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(404);return;}
if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer book-test-key-1234567890'){http_response_code(401);echo '{"error":"unauthorized"}';return;}
$body=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($body)||($body['stream']??null)!==true||($body['store']??null)!==false||!is_array($body['input']??null)||($body['max_output_tokens']??0)>2048){http_response_code(400);return;}
header('Content-Type: text/event-stream');header('Cache-Control: no-cache');header('X-Accel-Buffering: no');
$emit=static function(array $payload){echo "event: ".$payload['type']."\ndata: ".json_encode($payload,JSON_UNESCAPED_UNICODE)."\n\n";flush();};
$last=end($body['input']);if(str_contains((string)($last['content']??''),'simulate upstream failure')){$emit(['type'=>'response.failed','response'=>['error'=>['code'=>'test_error']]]);return;}
$emit(['type'=>'response.output_text.delta','delta'=>'Documentation ']);usleep(50000);
$emit(['type'=>'response.output_text.delta','delta'=>'answer [1].']);usleep(50000);
$emit(['type'=>'response.completed','response'=>['usage'=>['input_tokens'=>84,'output_tokens'=>9]]]);
