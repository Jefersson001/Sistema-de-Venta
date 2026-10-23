<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/php/bcv-provider.php';
try{echo json_encode(bcvFetchPublication(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
