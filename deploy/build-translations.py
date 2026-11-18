#!/usr/bin/env python3
"""Build the small, source-controlled gateway gettext catalog without compiler dependencies."""
from pathlib import Path
import json
import struct

ROOT=Path(__file__).resolve().parents[1]
DIRECTORY=ROOT/'wp-content/plugins/atlas-gateways/languages'
DIRECTORY.mkdir(exist_ok=True)
messages={
    'Unavailable: provider contract, security verification and merchant approval are required.':'در دسترس نیست: قرارداد ارائه‌دهنده، بررسی امنیتی و تأیید پذیرنده لازم است.',
    'This payment method is unavailable.':'این روش پرداخت در دسترس نیست.',
    'Atlas local simulator':'شبیه‌ساز محلی اطلس',
    'Simulated payment (local only)':'پرداخت آزمایشی (فقط محیط محلی)',
    'No funds are transferred. This method is available only in an explicitly enabled local environment.':'هیچ وجهی جابه‌جا نمی‌شود. این روش فقط در محیط محلیِ فعال‌شده در دسترس است.',
    'No funds are transferred.':'هیچ وجهی جابه‌جا نمی‌شود.',
    'Simulated payment is unavailable.':'پرداخت آزمایشی در دسترس نیست.',
    'The order cannot be paid with this method.':'این سفارش با این روش قابل پرداخت نیست.',
    'The order amount is invalid.':'مبلغ سفارش معتبر نیست.',
    'A valid reservation is required for this order.':'این سفارش به رزرو معتبر نیاز دارد.',
    'This order needs separate payment.':'این سفارش به پرداخت جداگانه نیاز دارد.',
    'Booking service is unavailable.':'خدمت رزرو در دسترس نیست.',
    'The order and reservation do not match.':'سفارش و رزرو با یکدیگر مطابقت ندارند.',
    'Simulation could not be verified.':'پرداخت آزمایشی تأیید نشد.',
    'The reservation requires manual review.':'رزرو به بررسی مسئول مربوط نیاز دارد.',
    'The reservation expired or changed. No simulated payment was initiated.':'مهلت یا شرایط رزرو تغییر کرده است. هیچ پرداخت آزمایشی آغاز نشد.',
    'Payment verified; booking requires manual review.':'پرداخت تأیید شد؛ رزرو به بررسی مسئول مربوط نیاز دارد.',
    'The reservation expired. Please reserve again.':'مهلت رزرو پایان یافته است. دوباره رزرو کنید.',
}
header='Project-Id-Version: atlas-gateways 0.1.0\nLanguage: fa_IR\nContent-Type: text/plain; charset=UTF-8\nPlural-Forms: nplurals=2; plural=(n > 1);\n'
catalog={'':header,**messages}
keys=sorted(catalog)
originals=[key.encode() for key in keys]
translated=[catalog[key].encode() for key in keys]
count=len(keys); original_offset=28; translated_offset=28+count*8
offset=28+count*16; original_table=[]; translation_table=[]
for item in originals:
    original_table.append((len(item),offset)); offset+=len(item)+1
for item in translated:
    translation_table.append((len(item),offset)); offset+=len(item)+1
data=struct.pack('<7I',0x950412de,0,count,original_offset,translated_offset,0,0)
data+=b''.join(struct.pack('<2I',*entry) for entry in original_table+translation_table)
data+=b''.join(item+b'\0' for item in originals+translated)
(DIRECTORY/'atlas-gateways-fa_IR.mo').write_bytes(data)
po=['# Generated Persian draft; native financial/legal review required.\nmsgid ""\nmsgstr '+json.dumps(header,ensure_ascii=False)+'\n']
for key in sorted(messages):
    po.append('msgid '+json.dumps(key,ensure_ascii=False)+'\nmsgstr '+json.dumps(messages[key],ensure_ascii=False)+'\n')
(DIRECTORY/'atlas-gateways-fa_IR.po').write_text('\n'.join(po))
print('Built draft Persian gettext catalog:',len(messages),'messages; native review pending.')
