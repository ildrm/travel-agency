// Parent-scheduled lightweight node fixture; no camera, DOM, network or runtime installation.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import encodeQR from '../wp-content/plugins/atlas-operations/assets/third-party/qr-0.7.0/index.js';
import decodeQR from '../wp-content/plugins/atlas-operations/assets/third-party/qr-0.7.0/decode.js';
const base=new URL('../wp-content/plugins/atlas-operations/assets/third-party/qr-0.7.0/',import.meta.url);
const manifest=JSON.parse(fs.readFileSync(new URL('MANIFEST.json',base)));
let checks=0;const check=(condition,message)=>{assert.ok(condition,message);checks++;};
for(const entry of manifest.files){const data=fs.readFileSync(new URL(entry.file,base));check(crypto.createHash('sha256').update(data).digest('hex')===entry.sha256,'Pinned vendor bytes match manifest: '+entry.file);}
const reference='1.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.1.'+'a'.repeat(64);
const matrix=encodeQR(reference,'raw',{ecc:'medium',border:4,scale:1});
const scale=5,width=matrix.length*scale,pixels=new Uint8ClampedArray(width*width*4);
for(let y=0;y<width;y++)for(let x=0;x<width;x++){const offset=(y*width+x)*4;const color=matrix[Math.floor(y/scale)][Math.floor(x/scale)]?0:255;pixels[offset]=pixels[offset+1]=pixels[offset+2]=color;pixels[offset+3]=255;}
check(decodeQR({width,height:width,data:pixels},{effort:2,timeLimit:40})===reference,'Actual QR encoding and pixel decoding preserve the exact signed attendee payload.');
const svg=encodeQR(reference,'svg',{ecc:'medium',border:4});check(svg.startsWith('<svg')&&svg.includes('<path')&&!svg.includes(reference),'QR image contains encoded modules rather than a text/PII placeholder.');
let blankRejected=false;try{decodeQR({width:64,height:64,data:new Uint8ClampedArray(64*64*4).fill(255)},{effort:1,timeLimit:8});}catch(error){blankRejected=true;}check(blankRejected,'Blank image does not become an attendee reference.');
console.log(`Atlas event QR checks: ${checks} passed, 0 failed.`);
