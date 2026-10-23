<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bcv-sync.php';
try{$state=bcvSync(in_array('--force',$argv,true));if(!empty($state['error']))throw new RuntimeException($state['error']);echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
