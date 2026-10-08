/**
 * 打印图片按需加载工具（全局）
 *
 * 背景：原先各单据详情页在【服务端】把商品图片读出来转 base64 内联进页面，
 *       图片多/大时单个 HTML 会膨胀到几十上百 MB，浏览器必须下载并解析完
 *       才能结束加载，表现为打开详情页时标签一直转圈、无法操作。
 *
 * 方案：页面只输出图片路径（缩略图交给浏览器异步加载），
 *       只有点击「打印 / 导出PDF」时才在前端把图片压缩成 dataURL，
 *       并缓存在内存里，第二次打印无需再等待。
 */
(function (global) {
    'use strict';

    // 站点根路径：用于把 uploads/products/xxx.jpg 这类相对路径拼成绝对地址
    var SITE_BASE = (function () {
        var p = (location.pathname || '/').replace(/\\/g, '/');
        var i = p.indexOf('/modules/');
        return location.origin + (i >= 0 ? p.substring(0, i + 1) : p.replace(/[^\/]*$/, ''));
    })();

    function absUrl(path) {
        if (!path) return '';
        if (/^(https?:)?\/\//i.test(path)) return path;
        if (path.charAt(0) === '/') return location.origin + path;
        return SITE_BASE + path;
    }

    /**
     * 图片 -> 压缩后的 dataURL
     * 打印窗口是空白文档（about:blank），相对路径无法解析，用 dataURL 才能保证一定带得过去。
     * @param {string} url 图片地址
     * @param {number} maxWidth 最大宽度（默认 600），超出按比例压缩
     * @returns {Promise<string>} 失败返回空字符串
     */
    function imageToDataUrl(url, maxWidth) {
        maxWidth = maxWidth || 600;
        return new Promise(function (resolve) {
            var done = false;
            function finish(v) { if (!done) { done = true; resolve(v || ''); } }
            var timer = setTimeout(function () { finish(''); }, 15000);
            var img = new Image();
            img.onload = function () {
                clearTimeout(timer);
                try {
                    var w = img.naturalWidth || img.width;
                    var h = img.naturalHeight || img.height;
                    var scale = (w && w > maxWidth) ? maxWidth / w : 1;
                    var cw = Math.max(1, Math.round(w * scale));
                    var ch = Math.max(1, Math.round(h * scale));
                    var canvas = document.createElement('canvas');
                    canvas.width = cw;
                    canvas.height = ch;
                    var ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, cw, ch);
                    ctx.drawImage(img, 0, 0, cw, ch);
                    finish(canvas.toDataURL('image/jpeg', 0.85));
                } catch (e) {
                    finish('');
                }
            };
            img.onerror = function () { clearTimeout(timer); finish(''); };
            img.src = url;
        });
    }

    var dataUrlCache = {}; // url -> dataURL（压缩后），同图只处理一次

    /**
     * 为打印/导出准备图片：把 items 里的 product_image 转成 image_base64
     * @param {Array} items 明细数组（元素需含 product_image 字段）
     * @param {number} maxWidth 最大宽度
     * @returns {Promise<void>}
     */
    function preparePrintImages(items, maxWidth) {
        items = items || [];
        var urlMap = {};
        var urls = [];
        var i, it, u;

        for (i = 0; i < items.length; i++) {
            it = items[i];
            if (!it || !it.product_image || it.image_base64) continue;
            u = absUrl(it.product_image);
            if (!urlMap[u]) { urlMap[u] = []; urls.push(u); }
            urlMap[u].push(it);
        }
        if (!urls.length) return Promise.resolve();

        return Promise.all(urls.map(function (url) {
            var list = urlMap[url];
            if (dataUrlCache[url] !== undefined) {
                return Promise.resolve(dataUrlCache[url]);
            }
            return imageToDataUrl(url, maxWidth).then(function (d) {
                dataUrlCache[url] = d;
                return d;
            });
        })).then(function (results) {
            for (var k = 0; k < urls.length; k++) {
                var dataUrl = results[k];
                if (!dataUrl) continue;
                var list = urlMap[urls[k]];
                for (var j = 0; j < list.length; j++) list[j].image_base64 = dataUrl;
            }
        });
    }

    /**
     * 打印/导出动作包装：先把图片准备好（异步，不阻塞页面），期间按钮显示「准备中」
     * @param {Function} action 真正执行的打印/导出逻辑
     * @param {Object} options { items: 明细数组, ids: 按钮id数组, maxWidth: 最大宽度 }
     */
    function runPrintAction(action, options) {
        options = options || {};
        var ids = options.ids || ['btnPrint', 'btnExport'];
        var btns = [];
        var oldHtml = [];
        var i, b;

        for (i = 0; i < ids.length; i++) {
            b = document.getElementById(ids[i]);
            if (b) { btns.push(b); oldHtml.push(b.innerHTML); }
        }
        var busyText = '<i class="fa-solid fa-spinner fa-spin"></i> 图片准备中...';
        function setBusy(on) {
            for (var n = 0; n < btns.length; n++) {
                btns[n].disabled = on;
                btns[n].innerHTML = on ? busyText : oldHtml[n];
            }
        }

        setBusy(true);
        return preparePrintImages(options.items, options.maxWidth).then(function () {
            setBusy(false);
            action();
        }, function () {
            setBusy(false);
            action();
        });
    }

    /** 商品图片缩略图点击放大预览（自动创建弹窗，页面已有 #imagePreviewModal 则复用） */
    function previewImage(url) {
        var modal = document.getElementById('imagePreviewModal');
        if (!modal) {
            var wrap = document.createElement('div');
            wrap.innerHTML = '<div class="modal-overlay" id="imagePreviewModal" onclick="closeModal(\'imagePreviewModal\')">'
                + '<div style="max-width:90vw;max-height:90vh;position:relative;top:50%;left:50%;transform:translate(-50%,-50%);">'
                + '<img id="previewImage" src="" style="max-width:90vw;max-height:85vh;border-radius:8px;object-fit:contain;" alt="">'
                + '<button type="button" onclick="closeModal(\'imagePreviewModal\')" style="position:absolute;top:-35px;right:0;background:none;border:none;color:#fff;font-size:24px;cursor:pointer;">&times;</button>'
                + '</div></div>';
            while (wrap.firstChild) document.body.appendChild(wrap.firstChild);
            modal = document.getElementById('imagePreviewModal');
        }
        var img = document.getElementById('previewImage');
        if (img) img.src = url || '';
        if (typeof global.openModal === 'function') global.openModal('imagePreviewModal');
        else modal.classList.add('active');
    }

    global.absUrl = absUrl;
    global.imageToDataUrl = imageToDataUrl;
    global.preparePrintImages = preparePrintImages;
    global.runPrintAction = runPrintAction;
    global.previewImage = previewImage;
})(window);
