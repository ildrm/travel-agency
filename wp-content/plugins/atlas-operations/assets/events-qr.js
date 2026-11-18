(function () {
    'use strict';
    var source = document.currentScript && document.currentScript.src;
    if (!source) return;
    var base = new URL('third-party/qr-0.7.0/', source), encoder, decoder;
    var language = document.documentElement.lang.indexOf('fa') === 0 ? 'fa' : 'en';
    function text(en, fa) { return language === 'fa' ? fa : en; }
    function valid(value) { return typeof value === 'string' && /^1\.[a-f0-9-]{36}\.[a-f0-9-]{36}\.[1-9][0-9]{0,3}\.[a-f0-9]{64}$/.test(value); }
    function loadEncoder() { return encoder || (encoder = import(new URL('index.js', base).href)); }
    function loadDecoder() { return decoder || (decoder = import(new URL('decode.js', base).href)); }
    function svgDocument(markup) {
        if (markup.length > 250000 || /<!DOCTYPE|<!ENTITY/i.test(markup)) throw Error('invalid image');
        var documentSvg = new DOMParser().parseFromString(markup, 'image/svg+xml'), svg = documentSvg.documentElement;
        if (svg.localName !== 'svg' || svg.namespaceURI !== 'http://www.w3.org/2000/svg' || documentSvg.querySelector('parsererror')) throw Error('invalid image');
        var nodes = Array.from(svg.querySelectorAll('*')); if (nodes.length > 10000) throw Error('image too complex');
        [svg].concat(nodes).forEach(function (node) {
            if (!['svg', 'path', 'rect'].includes(node.localName)) throw Error('unsupported image element');
            Array.from(node.attributes).forEach(function (attribute) {
                if (!['xmlns', 'viewBox', 'width', 'height', 'x', 'y', 'fill', 'd', 'shape-rendering'].includes(attribute.name)) throw Error('unsupported image attribute');
                if (attribute.name === 'fill' && !/^(?:black|white|none|#[a-f0-9]{3,8})$/i.test(attribute.value)) throw Error('invalid image color');
                if (attribute.name === 'd' && !/^[MmLlHhVvZz0-9 .,+-]+$/.test(attribute.value)) throw Error('invalid image path');
                if (['width', 'height', 'x', 'y'].includes(attribute.name) && !/^(?:0|[1-9][0-9]{0,3})$/.test(attribute.value)) throw Error('invalid image dimension');
                if (attribute.name === 'shape-rendering' && attribute.value !== 'crispEdges') throw Error('invalid image rendering');
            });
        });
        var view = (svg.getAttribute('viewBox') || '').trim().split(/\s+/).map(Number);
        if (view.length !== 4 || view[0] !== 0 || view[1] !== 0 || !Number.isFinite(view[2]) || !Number.isFinite(view[3]) || view[2] < 21 || view[3] < 21 || view[2] > 4096 || view[3] > 4096) throw Error('invalid view box');
        return svg;
    }
    async function encode(reference) {
        if (!valid(reference)) throw Error('invalid attendee reference');
        var library = await loadEncoder(), svg = svgDocument(library.encodeQR(reference, 'svg', { ecc: 'medium', border: 4, scale: 1 }));
        svg.setAttribute('width', '512'); svg.setAttribute('height', '512'); svg.setAttribute('shape-rendering', 'crispEdges');
        var background = svg.ownerDocument.createElementNS('http://www.w3.org/2000/svg', 'rect');
        var view = svg.getAttribute('viewBox').split(/\s+/); background.setAttribute('width', view[2]); background.setAttribute('height', view[3]); background.setAttribute('fill', 'white'); svg.prepend(background);
        return new XMLSerializer().serializeToString(svg);
    }
    async function decodePixels(canvas) {
        var library = await loadDecoder(), context = canvas.getContext('2d', { willReadFrequently: true });
        var reference = library.decodeQR(context.getImageData(0, 0, canvas.width, canvas.height), { effort: 2, timeLimit: 40 });
        if (!valid(reference)) throw Error('not a signed attendee reference'); return reference;
    }
    async function decodeFile(file) {
        if (!file || file.size > 5 * 1024 * 1024) throw Error('image too large');
        var type = file.type, bitmap, objectUrl;
        try { if (type === 'image/svg+xml') {
            var safe = svgDocument(await file.text()); safe.setAttribute('width', '1024'); safe.setAttribute('height', '1024');
            objectUrl = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(safe)], { type: 'image/svg+xml' }));
            bitmap = await new Promise(function (resolve, reject) { var image = new Image(); image.onload = function () { resolve(image); }; image.onerror = reject; image.src = objectUrl; });
        } else {
            if (!['image/png', 'image/jpeg', 'image/webp', 'image/gif'].includes(type)) throw Error('unsupported image');
            bitmap = await createImageBitmap(file);
        }
            if (bitmap.width > 4096 || bitmap.height > 4096 || !bitmap.width || !bitmap.height) throw Error('image dimensions too large');
            var ratio = Math.min(1, 1280 / Math.max(bitmap.width, bitmap.height)), canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * ratio)); canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
            var context = canvas.getContext('2d', { willReadFrequently: true }); context.fillStyle = 'white'; context.fillRect(0, 0, canvas.width, canvas.height); context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            return await decodePixels(canvas);
        } finally { if (bitmap && bitmap.close) bitmap.close(); if (objectUrl) URL.revokeObjectURL(objectUrl); }
    }
    document.querySelectorAll('[data-event-reference]').forEach(function (code) {
        var button = document.createElement('button'), status = document.createElement('p'); button.type = 'button'; button.textContent = text('Generate attendee QR image', 'تولید تصویر QR حضور'); status.setAttribute('role', 'status');
        code.parentElement.append(button, status);
        button.addEventListener('click', async function () {
            button.disabled = true;
            try { var svg = await encode(code.dataset.eventReference), url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' })); var image = new Image(); image.alt = text('Signed attendee QR; share only with assigned check-in staff.', 'QR امضاشده حضور؛ فقط با کارکنان پذیرش تعیین‌شده به اشتراک بگذارید.'); image.width = 256; image.height = 256; image.src = url; image.dataset.eventQr = '1';
                var link = document.createElement('a'); link.href = url; link.download = 'attendee-reference.svg'; link.textContent = text('Download QR image', 'دریافت تصویر QR'); status.replaceChildren(image, link);
            } catch (error) { status.textContent = text('QR image could not be generated. Keep the signed text reference.', 'تصویر QR تولید نشد؛ شناسه متنی امضاشده را نگه دارید.'); }
            finally { button.disabled = false; }
        });
    });
    var stopActive;
    document.querySelectorAll('[data-event-offline]').forEach(function (panel) {
        var field = panel.querySelector('[data-event-scans]'), status = panel.querySelector('[role=status]'), video = document.createElement('video'); video.playsInline = true; video.muted = true; video.hidden = true;
        var prepare = document.createElement('button'), start = document.createElement('button'), stop = document.createElement('button'), fileLabel = document.createElement('label'), file = document.createElement('input');
        [prepare, start, stop].forEach(function (button) { button.type = 'button'; }); prepare.textContent = text('Prepare scanner before going offline', 'آماده‌سازی اسکنر پیش از آفلاین‌شدن'); start.textContent = text('Start camera scanning', 'آغاز اسکن با دوربین'); stop.textContent = text('Stop camera', 'توقف دوربین'); stop.disabled = true;
        file.type = 'file'; file.accept = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml'; file.dataset.eventQrFile = '1'; fileLabel.append(document.createTextNode(text('Decode a QR image locally (up to 5 MB)', 'خواندن محلی تصویر QR (حداکثر ۵ مگابایت)')), file); panel.append(prepare, start, stop, fileLabel, video);
        var stream, timer, busy = false, generation = 0;
        function stopCamera() { generation++; if (timer) clearInterval(timer); timer = undefined; if (stream) stream.getTracks().forEach(function (track) { track.stop(); }); stream = undefined; video.srcObject = null; video.hidden = true; start.disabled = false; stop.disabled = true; }
        function queue(reference) { if (!valid(reference)) throw Error('invalid reference'); field.value = reference; panel.querySelector('[data-queue-event-scans]').click(); }
        prepare.addEventListener('click', async function () { prepare.disabled = true; try { await Promise.all([loadEncoder(), loadDecoder()]); status.textContent = text('Scanner loaded. Keep this page open for offline capture; server sync still requires connectivity.', 'اسکنر آماده شد. برای ثبت آفلاین این صفحه را باز نگه دارید؛ همگام‌سازی سرور همچنان نیازمند ارتباط است.'); } catch (error) { status.textContent = text('Scanner preparation failed. Signed text entry remains available.', 'آماده‌سازی اسکنر انجام نشد. ورود شناسه متنی همچنان در دسترس است.'); } finally { prepare.disabled = false; } });
        file.addEventListener('change', async function () { stopCamera(); file.disabled = true; try { queue(await decodeFile(file.files[0])); } catch (error) { status.textContent = text('No valid attendee QR found. Choose a clear supported image or paste the signed reference.', 'QR معتبر حضور پیدا نشد. تصویر واضح و پشتیبانی‌شده را انتخاب یا شناسه امضاشده را وارد کنید.'); } finally { file.disabled = false; file.value = ''; } });
        start.addEventListener('click', async function () {
            if (stopActive) stopActive(); stopActive = stopCamera; var attempt = ++generation; start.disabled = true; stop.disabled = false;
            try { if (!isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw Error('camera unavailable'); await loadDecoder();
                // Camera is requested only from this explicit user click handler.
                if (attempt !== generation || document.hidden) return;
                var opened = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: 'environment', width: { ideal: 640 }, height: { ideal: 480 } } });
                if (attempt !== generation || document.hidden) { opened.getTracks().forEach(function (track) { track.stop(); }); return; }
                stream = opened; video.srcObject = stream; video.hidden = false; await video.play(); if (attempt !== generation) return; stop.disabled = false;
                timer = setInterval(async function () { if (busy || !stream || !video.videoWidth || document.hidden) return; busy = true; try { var ratio = Math.min(1, 640 / video.videoWidth), canvas = document.createElement('canvas'); canvas.width = Math.round(video.videoWidth * ratio); canvas.height = Math.round(video.videoHeight * ratio); canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height); var reference = await decodePixels(canvas); stopCamera(); queue(reference); } catch (error) { /* A frame miss contains no attendance decision. */ } finally { busy = false; } }, 250);
                status.textContent = text('Camera active; point at a signed attendee QR. Stop releases the camera.', 'دوربین فعال است؛ QR امضاشده حضور را مقابل آن بگیرید. توقف، دوربین را آزاد می‌کند.');
            } catch (error) { if (attempt !== generation) return; stopCamera(); status.textContent = text('Camera unavailable or permission denied. Choose an image or enter the signed text instead.', 'دوربین در دسترس نیست یا اجازه داده نشد. تصویر را انتخاب یا متن امضاشده را وارد کنید.'); }
        });
        stop.addEventListener('click', stopCamera); window.addEventListener('pagehide', stopCamera); document.addEventListener('visibilitychange', function () { if (document.hidden) stopCamera(); });
    });
    window.AtlasEventQR = Object.freeze({ encode: encode, decodeFile: decodeFile, isSignedReference: valid });
}());
