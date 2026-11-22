// Local TLS SMTP sink for isolated integration tests. Never forwards messages.
const tls=require('node:tls'),fs=require('node:fs');
const [cert,key,ready,outbox]=process.argv.slice(2);
const server=tls.createServer({cert:fs.readFileSync(cert),key:fs.readFileSync(key)},socket=>{
 let buffer='',data=false,lines=[],authStep=0,user='';socket.setEncoding('utf8');socket.write('220 localhost test SMTP\r\n');
 socket.on('data',chunk=>{buffer+=chunk;let pos;while((pos=buffer.indexOf('\n'))>=0){const line=buffer.slice(0,pos).replace(/\r$/,'');buffer=buffer.slice(pos+1);
  if(data){if(line==='.') {fs.appendFileSync(outbox,JSON.stringify({user,message:lines.join('\r\n')})+'\n');data=false;lines=[];socket.write('250 accepted locally\r\n');}else lines.push(line.startsWith('..')?line.slice(1):line);continue;}
  if(authStep){if(authStep===1){user=Buffer.from(line,'base64').toString();authStep=2;socket.write('334 UGFzc3dvcmQ6\r\n');}else{authStep=0;socket.write('235 authenticated\r\n');}continue;}
  if(/^EHLO|^HELO/.test(line))socket.write('250-localhost\r\n250-AUTH LOGIN\r\n250 SIZE 20000000\r\n');
  else if(/^AUTH LOGIN/.test(line)){authStep=1;socket.write('334 VXNlcm5hbWU6\r\n');}
  else if(/^MAIL FROM|^RCPT TO|^RSET/.test(line))socket.write('250 OK\r\n');
  else if(line==='DATA'){data=true;socket.write('354 send data\r\n');}
  else if(line==='QUIT'){socket.end('221 bye\r\n');}
  else socket.write('250 OK\r\n');
 }});socket.on('error',()=>{});
});server.on('tlsClientError',()=>{});server.listen(0,'127.0.0.1',()=>fs.writeFileSync(ready,String(server.address().port)));
