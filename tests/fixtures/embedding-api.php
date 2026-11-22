<?php
// Test-only local OpenAI embeddings protocol fixture, never deployed under public/.
header('Content-Type: application/json');
if($_SERVER['REQUEST_METHOD']!=='POST'||parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/v1/embeddings'){http_response_code(404);exit;}
$input=json_decode(file_get_contents('php://input'),true);
if(($input['model']??null)!=='book-test-v1'||!is_array($input['input']??null)){http_response_code(422);exit;}
$data=[];
foreach($input['input'] as $i=>$text){
    // Map distinct vocabulary to the same semantic concept for a predictable test.
    $text=strtolower((string)$text);
    $a=str_contains($text,'connection')||str_contains($text,'network')||str_contains($text,'ethernet');
    $b=str_contains($text,'mountain')||str_contains($text,'alpine');
    $data[]=['object'=>'embedding','index'=>$i,'embedding'=>$a?[1,0,0,0]:($b?[0,1,0,0]:[0,0,1,0])];
}
echo json_encode(['object'=>'list','model'=>$input['model'],'data'=>$data],JSON_THROW_ON_ERROR);
