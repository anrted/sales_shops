const https = require('https');
const fs = require('fs');
const envPath = 'C:/OSPanel/home/xeber.loc/discounts.loc/backend/.env';
const env = Object.fromEntries(fs.readFileSync(envPath, 'utf8').split(/\r?\n/).filter(Boolean).filter(line => !line.startsWith('#') && line.includes('=')).map(line => { const i = line.indexOf('='); return [line.slice(0,i), line.slice(i+1).replace(/^"|"$/g, '')]; }));
const body = JSON.stringify({categories:[47161],includeAdultGoods:true,pagination:{limit:1,offset:0},sort:{order:'desc',type:'discount'},storeCode:'230972',storeType:'1',catalogType:'1'});
const req = https.request('https://magnit.ru/webgate/v2/goods/search', {
  method: 'POST',
  headers: {
    'accept': 'application/json',
    'accept-language': env.MAGNIT_ACCEPT_LANGUAGE || 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
    'content-type': 'application/json',
    'origin': env.MAGNIT_ORIGIN || 'https://magnit.ru',
    'x-device-id': env.MAGNIT_DEVICE_ID || '',
    'cookie': env.MAGNIT_RAW_COOKIE_HEADER || '',
    ...(env.MAGNIT_BAGGAGE ? {'baggage': env.MAGNIT_BAGGAGE} : {}),
    'content-length': Buffer.byteLength(body)
  }
}, (res) => {
  let data='';
  res.on('data', c => data += c);
  res.on('end', () => {
    console.log('STATUS', res.statusCode);
    console.log(data.slice(0, 500));
  });
});
req.on('error', (err) => { console.error('ERROR', err.message); process.exit(1); });
req.write(body);
req.end();
