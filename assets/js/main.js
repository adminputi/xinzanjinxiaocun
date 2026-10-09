/**
 * 进销存管理系统 - 主脚本
 */
(function() {
    'use strict';

    // 侧边栏切换
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    };

    // 导航组折叠
    window.toggleNavGroup = function(el) {
        el.classList.toggle('open');
        const items = el.nextElementSibling;
        if (items) {
            items.classList.toggle('open');
        }
        // 保存状态
        const title = el.textContent.trim();
        const opened = JSON.parse(localStorage.getItem('nav_opened') || '{}');
        opened[title] = items && items.classList.contains('open');
        localStorage.setItem('nav_opened', JSON.stringify(opened));
    };

    // 恢复导航折叠状态
    document.addEventListener('DOMContentLoaded', function() {
        const opened = JSON.parse(localStorage.getItem('nav_opened') || '{}');
        document.querySelectorAll('.nav-group-title').forEach(function(el) {
            const title = el.textContent.trim();
            if (opened[title]) {
                el.classList.add('open');
                const items = el.nextElementSibling;
                if (items) items.classList.add('open');
            }
        });
    });

    // ============ 全局 CSRF 令牌刷新 ============
    // 服务端在令牌失效时会下发新令牌，统一写回页面所有隐藏域
    window.refreshCsrfToken = function(token) {
        if (!token) return;
        document.querySelectorAll('input[name="_csrf_token"]').forEach(function(inp) {
            inp.value = token;
        });
        // CRM / 产品列表等页面把令牌写死在 JS 变量里，这里同步更新，避免刷新页面才恢复
        if (typeof window.CSRF_TOKEN !== 'undefined') window.CSRF_TOKEN = token;
        if (typeof window.csrfToken !== 'undefined') window.csrfToken = token;
    };

    // ============ fetch 增强：非 JSON 响应给出可读提示 ============
    // 服务器 die()/PHP 报错/登录跳转返回的都是 HTML，前端 r.json() 只会抛出
    // "Unexpected token '<' ..."，用户完全看不懂，这里统一换成中文提示，
    // 并在令牌过期时自动把新令牌写回页面
    (function enhanceFetch() {
        if (!window.fetch) return;
        var origFetch = window.fetch.bind(window);
        // 仅对明确期望 JSON 的请求做包装，避免给 AJAX 导航（取 HTML）增加多余的响应副本
        function headersWantJson(h) {
            if (!h || typeof h !== 'object') return false;
            var get = function(k) {
                if (typeof h.get === 'function') return h.get(k);
                for (var key in h) {
                    if (String(key).toLowerCase() === k) return h[key];
                }
                return null;
            };
            var xrw = String(get('x-requested-with') || '').toLowerCase();
            var accept = String(get('accept') || '').toLowerCase();
            return xrw === 'xmlhttprequest' || accept.indexOf('application/json') !== -1;
        }
        window.fetch = function(input, init) {
            var wantsJson = headersWantJson(init && init.headers)
                || (typeof Request !== 'undefined' && input instanceof Request && headersWantJson(input.headers));
            var p = origFetch.apply(null, arguments);
            if (!wantsJson) return p;
            return p.then(function(resp) {
                if (!resp || typeof resp.json !== 'function' || resp.__jsonEnhanced) return resp;
                var snapshot = null;
                try { snapshot = resp.clone(); } catch (e) { return resp; }
                try {
                    Object.defineProperty(resp, '__jsonEnhanced', { value: true });
                    Object.defineProperty(resp, 'json', {
                        value: function() {
                            return snapshot.text().then(function(txt) {
                                try {
                                    var data = JSON.parse(txt);
                                    if (data && data.csrf_expired && data.csrf_token && window.refreshCsrfToken) {
                                        window.refreshCsrfToken(data.csrf_token);
                                    }
                                    return data;
                                } catch (e) {
                                    var snippet = String(txt || '').replace(/\s+/g, ' ').trim().slice(0, 100);
                                    if (/安全验证失败|页面已过期/.test(txt)) {
                                        throw new Error('页面已过期（安全验证失败），请刷新页面后重试');
                                    }
                                    if (/登录|login/i.test(snippet)) {
                                        throw new Error('登录状态已失效，请刷新页面重新登录');
                                    }
                                    throw new Error('服务器未返回有效数据，请刷新页面后重试'
                                        + (snippet ? '（响应片段：' + snippet + '）' : ''));
                                }
                            });
                        }
                    });
                } catch (e) {}
                return resp;
            });
        };
    })();

    // 页面内联脚本位于 </main> 之后（在 main.js 之后），浏览器尚未执行；
    // 由 footer.php 在本脚本加载完成后立即调用 __bootApp 执行它们，
    // 否则首屏页面函数（printQuote 等）未定义，且初始化顺序不确定
    var booted = false;
    window.__bootApp = function() {
        if (booted) return;
        booted = true;
        try {
            executeScripts(document.querySelector('.content-wrapper'));
            rebindPageForms();
            // 初始化可搜索下拉（客户/供应商等长列表）
            if (window.initSearchableSelects) window.initSearchableSelects();
            // 初始化AJAX导航
            initAjaxNav();
        } catch (e) {
            console.error('页面初始化失败:', e);
        }
    };

    // 兜底：若 footer.php 未调用（如单独引入 main.js 的页面），DOM 就绪后再执行
    document.addEventListener('DOMContentLoaded', function() {
        if (!booted) window.__bootApp();
    });

    // ============ AJAX 导航系统 ============
    var isNavigating = false;

    // 这些页面含表单提交后 redirect 的 PRG 逻辑（或文件下载响应），
    // AJAX 拼接 HTML 会在跟随 302 时把内容区留空甚至卡住导航，一律整页跳转
    var FULL_PAGE_WHITELIST = /(^|\/)([a-z0-9_]*(_form|_edit|_add|_new|_save|_print|_export|_view|login|logout))\.php(\?|$)/i;
    var FULL_PAGE_BLOCKLIST = /(^|\/)(backup|restore|install|update|export)[a-z0-9_]*\.php(\?|$)/i;

    function shouldFullLoad(url) {
        var path = url.split('#')[0].split('?')[0];
        return FULL_PAGE_WHITELIST.test(path) || FULL_PAGE_BLOCKLIST.test(path);
    }

    var ajaxNavInited = false;
    function initAjaxNav() {
        if (ajaxNavInited) return;
        ajaxNavInited = true;
        // 拦截侧边栏导航链接（事件委托：内容刷新后无需重新绑定）
        document.addEventListener('click', function(e) {
            var link = e.target.closest ? e.target.closest('.sidebar-nav a.nav-item') : null;
            if (!link) return;
            // 不拦截右键/中键/修饰键
            if (e.ctrlKey || e.metaKey || e.button !== 0) return;
            e.preventDefault();
            var url = link.getAttribute('href');
            if (!url) return;
            // 如果点击的是当前已激活的链接，不做任何事
            if (link.classList.contains('active')) return;
            // 移动端：点击导航链接后自动关闭侧边栏
            if (window.innerWidth <= 1024) toggleSidebar();
            // 编辑/表单类页面：整页跳转，避免 PRG 跳转在 AJAX 下空白
            if (link.getAttribute('data-full') === '1' || shouldFullLoad(url)) {
                window.location = url;
                return;
            }
            navigateTo(url);
        });

        // 监听浏览器前进/后退
        window.addEventListener('popstate', function(e) {
            var url = location.href;
            if (shouldFullLoad(url)) { window.location.reload(); return; }
            navigateTo(url, true);
        });
    }

    function navigateTo(url, isPop) {
        if (isNavigating) return;
        isNavigating = true;
        // 安全阀：任何异常/超时都不会卡住整站导航
        var navDone = false;
        var finish = function() { isNavigating = false; };
        var safety = setTimeout(function() {
            if (navDone) return;
            navDone = true;
            console.warn('AJAX 导航超时，回退为整页跳转:', url);
            window.location = url;
        }, 8000);

        // 将URL转换为绝对路径：使用浏览器原生方式解析
        // 对于根相对路径(/开头)直接使用，对于其他相对路径使用锚点解析
        if (url.indexOf('/') === 0) {
            // 已经是根相对路径，直接使用
        } else if (url.indexOf('http') !== 0) {
            // 相对路径，使用锚点元素让浏览器自然解析
            var a = document.createElement('a');
            a.href = url;
            url = a.pathname + a.search + a.hash;
        }

        fetch(url, {
            headers: { 'X-Nav': '1' },
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.text();
        })
        .then(function(html) {
            if (navDone) return;
            // 整页兜底：如果响应本身就是「跳转中」页面（服务端 redirect 未生效），直接跟随其目标
            if (/正在跳转/.test(html) && html.length < 1200) {
                navDone = true;
                clearTimeout(safety);
                var m = html.match(/location\.replace\(([^)]+)\)/);
                var target = url;
                if (m) { try { target = JSON.parse(m[1]); } catch (e) {} }
                window.location = target;
                return;
            }

            var curWrapper = document.querySelector('.content-wrapper');
            if (!curWrapper) { navDone = true; clearTimeout(safety); window.location = url; return; }

            // 统一用 DOMParser 解析
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');

            // 提取面包屑（标题降级时需要用到）
            var crumbEl = doc.querySelector('.breadcrumb');

            // 提取标题（优先使用 page-title-text，其次用面包屑）
            var titleEl = doc.querySelector('page-title-text');
            var titleText = titleEl ? titleEl.textContent.trim() : '';
            if (titleText) {
                document.title = titleText;
            } else {
                // 降级：从面包屑提取 + 从当前title提取站点名
                var crumbSpan = crumbEl ? crumbEl.querySelector('span:last-child') : null;
                var crumbText = crumbSpan ? crumbSpan.textContent.trim() : '';
                var siteName = document.title.replace(/^.* - /, '');
                if (crumbText && siteName) document.title = crumbText + ' - ' + siteName;
            }

            // 更新面包屑
            var curBreadcrumb = document.querySelector('.breadcrumb');
            if (crumbEl && curBreadcrumb) {
                curBreadcrumb.innerHTML = crumbEl.innerHTML;
            }

            // 提取内容
            var newWrapper = doc.querySelector('.content-wrapper');
            if (newWrapper) {
                // 收集原页面内联 <script>（innerHTML 不会执行脚本，需要在替换后按序重建）
                var pendingScripts = [];
                Array.prototype.forEach.call(newWrapper.querySelectorAll('script'), function(s) {
                    pendingScripts.push({ text: s.textContent, src: s.getAttribute('src') });
                    s.parentNode.removeChild(s);
                });
                curWrapper.innerHTML = newWrapper.innerHTML;
                // 先重建脚本，让页面函数（printQuote 等）在回调可用后再做后续初始化
                Array.prototype.forEach.call(pendingScripts, function(item) {
                    var ns = document.createElement('script');
                    if (item.src) { ns.src = item.src; ns.async = false; }
                    else { ns.textContent = item.text; }
                    curWrapper.appendChild(ns);
                });
                // 新页面内容注入后重新初始化可搜索下拉
                if (window.initSearchableSelects) window.initSearchableSelects();
                // 重新绑定页面级表单（各页面 script 重新执行后会自行覆盖）
                rebindPageForms();
            } else {
                navDone = true;
                clearTimeout(safety);
                window.location = url;
                return;
            }

            // 更新URL（popstate事件由浏览器触发，不需要pushState）
            if (!isPop) {
                history.pushState(null, '', url);
            }

            // 更新侧边栏激活状态
            updateActiveNav(url);

            // 滚动到顶部
            window.scrollTo(0, 0);
            navDone = true;
            clearTimeout(safety);
        })
        .catch(function() {
            navDone = true;
            clearTimeout(safety);
            window.location = url;
        })
        .finally(function() {
            isNavigating = false;
        });
    }

    function updateActiveNav(url) {
        document.querySelectorAll('.nav-item.active').forEach(function(n) {
            n.classList.remove('active');
        });
        document.querySelectorAll('.nav-item').forEach(function(link) {
            var href = link.getAttribute('href');
            if (!href) return;
            // 统一比较路径部分，忽略协议和域名
            var linkPath = href.replace(/^https?:\/\/[^\/]+/, '');
            var currentPath = url.replace(/^https?:\/\/[^\/]+/, '');
            if (linkPath === currentPath || currentPath.indexOf(linkPath) !== -1 && linkPath.length > 1) {
                link.classList.add('active');
                // 确保父级导航组展开
                var group = link.closest('.nav-group-items');
                if (group) group.classList.add('open');
                var title = group ? group.previousElementSibling : null;
                if (title && title.classList.contains('nav-group-title')) title.classList.add('open');
            }
        });
    }

    // 执行内容区内的内联脚本：浏览器解析到 main.js（同步脚本）时会阻塞，
    // 页面自己的 <script> 尚未执行；innerHTML 注入的脚本同样不会自动执行，
    // 因此统一由这里按原顺序重建脚本节点执行
    function executeScripts(container) {
        if (!container) return;
        var scripts = container.querySelectorAll('script');
        Array.prototype.forEach.call(scripts, function(oldScript) {
            var newScript = document.createElement('script');
            for (var i = 0; i < oldScript.attributes.length; i++) {
                newScript.setAttribute(oldScript.attributes[i].name, oldScript.attributes[i].value);
            }
            var src = oldScript.getAttribute('src');
            if (src) {
                newScript.async = false;
                newScript.src = src;
            } else {
                newScript.textContent = oldScript.textContent;
            }
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    // 重新绑定页面级表单提交（AJAX 导航后新注入的 DOM 需要重绑）
    function rebindPageForms() {
        try {
            // 通用：带 data-confirm 的表单统一确认；有 onsubmit 内联属性的不处理
            document.querySelectorAll('form[data-confirm]').forEach(function(f) {
                if (f.__boundConfirm) return;
                f.__boundConfirm = true;
                f.addEventListener('submit', function(e) {
                    if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault();
                });
            });
            if (typeof window.rebindPageForms === 'function') window.rebindPageForms();
        } catch (e) { console.error('rebindPageForms error:', e); }
    }

    // 弹窗控制
    window.openModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
            // 焦点第一个input
            const firstInput = modal.querySelector('input:not([type=hidden])');
            if (firstInput) setTimeout(function() { firstInput.focus(); }, 100);
        }
    };

    window.closeModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
        // 重置表单
        const form = modal ? modal.querySelector('form') : null;
        if (form) form.reset();
    };

    // 点击遮罩关闭弹窗
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('show')) {
            closeModal(e.target.id);
        }
    });

    // ESC关闭弹窗
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal-overlay.show');
            if (openModal) closeModal(openModal.id);
        }
    });

    // 确认框
    window.confirmAction = function(message, callback) {
        if (confirm(message)) {
            callback();
        }
    };

    // AJAX请求封装
    window.apiRequest = function(options) {
        const defaultOptions = {
            method: 'POST',
            dataType: 'json',
            showLoading: true,
            timeout: 30000
        };
        const opts = Object.assign({}, defaultOptions, options);

        if (opts.showLoading) {
            showLoading();
        }

        return fetch(opts.url, {
            method: opts.method,
            headers: Object.assign({
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }, opts.headers || {}),
            body: opts.data instanceof FormData ? opts.data : opts.data ? new URLSearchParams(opts.data).toString() : null,
            signal: AbortSignal.timeout ? AbortSignal.timeout(opts.timeout) : undefined
        }).then(function(response) {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.json();
        }).then(function(result) {
            hideLoading();
            if (!result.success && result.message) {
                showToast(result.message, 'error');
            }
            return result;
        }).catch(function(error) {
            hideLoading();
            showToast('网络请求失败: ' + error.message, 'error');
            return { success: false, message: error.message };
        });
    };

    // Loading
    function showLoading() {
        let loader = document.getElementById('global-loading');
        if (!loader) {
            loader = document.createElement('div');
            loader.id = 'global-loading';
            loader.innerHTML = '<div class="loader-spinner"></div>';
            loader.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.6);z-index:9999;display:flex;align-items:center;justify-content:center;';
            document.body.appendChild(loader);
        }
        loader.style.display = 'flex';
    }

    function hideLoading() {
        const loader = document.getElementById('global-loading');
        if (loader) loader.style.display = 'none';
    }

    // Toast 消息
    window.showToast = function(message, type) {
        type = type || 'info';
        // 兼容 'danger'（Bootstrap 风格）与 'error'
        if (type === 'danger') type = 'error';
        const container = document.getElementById('toast-container') || createToastContainer();
        const toast = document.createElement('div');
        const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
        toast.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + '"></i> ' + message;
        toast.style.cssText = 'background:white;color:#333;padding:10px 16px;border-radius:8px;margin-bottom:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;display:flex;align-items:center;gap:8px;position:relative;z-index:100000;animation:slideIn 0.3s ease;border-left:3px solid ' + (colors[type] || colors.info) + ';';
        container.appendChild(toast);
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    };

    function createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;max-width:360px;';
        document.body.appendChild(container);
        return container;
    }

    // 表格行选择
    window.selectAllRows = function(checkbox) {
        document.querySelectorAll('.row-checkbox').forEach(function(cb) {
            cb.checked = checkbox.checked;
        });
    };

    // 获取选中行ID
    window.getSelectedIds = function() {
        const ids = [];
        document.querySelectorAll('.row-checkbox:checked').forEach(function(cb) {
            ids.push(cb.value);
        });
        return ids;
    };

    // 打印功能
    window.printElement = function(elementId) {
        const el = document.getElementById(elementId);
        if (!el) return;
        const win = window.open('', '_blank', 'width=800,height=600');
        win.document.write('<html><head><title>打印</title>');
        win.document.write('<link rel="stylesheet" href="../assets/css/style.css">');
        win.document.write('<style>body{background:white;padding:20px;}@media print{body{padding:0;}}</style>');
        win.document.write('</head><body>');
        win.document.write(el.outerHTML);
        win.document.write('</body></html>');
        win.document.close();
        setTimeout(function() { win.print(); }, 500);
    };

    // 导出CSV
    window.exportCsv = function(headers, data, filename) {
        let csv = '\uFEFF';
        csv += headers.join(',') + '\n';
        data.forEach(function(row) {
            csv += row.map(function(cell) {
                cell = String(cell).replace(/"/g, '""');
                return '"' + cell + '"';
            }).join(',') + '\n';
        });
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename || 'export.csv';
        link.click();
    };

    // 日期选择器初始化
    window.initDateRange = function(startId, endId) {
        const now = new Date();
        const monthAgo = new Date(now.getFullYear(), now.getMonth() - 1, now.getDate());
        document.getElementById(endId).valueAsDate = now;
        document.getElementById(startId).valueAsDate = monthAgo;
    };

    // Tabs切换
    window.switchTab = function(tabName, event) {
        const group = event ? event.target.closest('.tabs') : document.querySelector('.tabs');
        if (!group) return;
        group.querySelectorAll('.tab-item').forEach(function(t) { t.classList.remove('active'); });
        if (event) event.target.classList.add('active');

        const container = group.parentElement;
        container.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
        const content = container.querySelector('.tab-content[data-tab="' + tabName + '"]');
        if (content) content.classList.add('active');
    };

    // 数字输入限制
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('number-input') && e.target.type !== 'number') {
            e.target.value = e.target.value.replace(/[^\d.]/g, '');
        }
    });

    // 点击侧边栏外部关闭（移动端）
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (window.innerWidth <= 1024 && sidebar.classList.contains('show')) {
            if (!sidebar.contains(e.target) && !e.target.closest('.menu-trigger') && !e.target.closest('.sidebar-toggle')) {
                toggleSidebar();
            }
        }
    });

    // ============ 可搜索下拉选择器 ============
    // 用法：给 <select> 加 class="searchable" 即升级为「输入关键字过滤」的选择器；
    // 数据量大的可加 data-ajax="/api/search_partners.php?type=customer" 走远程搜索。
    var SEARCHABLE_STYLE_ID = 'searchable-style';

    function ensureSearchableStyle() {
        if (document.getElementById(SEARCHABLE_STYLE_ID)) return;
        var style = document.createElement('style');
        style.id = SEARCHABLE_STYLE_ID;
        style.textContent =
            '.searchable-wrapper{position:relative;}' +
            '.searchable-list{position:absolute;z-index:1200;left:0;right:0;top:100%;margin-top:2px;' +
            'max-height:260px;overflow-y:auto;background:#fff;border:1px solid #d0d5dd;border-radius:6px;' +
            'box-shadow:0 6px 18px rgba(0,0,0,.12);}' +
            '.searchable-item{padding:7px 10px;cursor:pointer;font-size:13px;display:flex;justify-content:space-between;gap:8px;}' +
            '.searchable-item:hover,.searchable-item.active{background:#e8f0fe;}' +
            '.searchable-item small{color:#667085;}' +
            '.searchable-empty{padding:8px 10px;color:#667085;cursor:default;}';
        document.head.appendChild(style);
    }

    function ajaxSearchOptions(sel, q, cb) {
        var base = sel.getAttribute('data-ajax') || '';
        if (!base) return;
        var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
        fetch(url).then(function(r) { return r.json(); }).then(function(data) {
            cb((data || []).map(function(d) {
                return { value: String(d.id), text: d.name || '', sub: d.sub || '' };
            }));
        }).catch(function(e) { console.error('搜索失败:', e); });
    }

    function buildSearchable(sel) {
        ensureSearchableStyle();
        var wrapper = document.createElement('div');
        wrapper.className = 'searchable-wrapper';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.autocomplete = 'off';
        input.placeholder = sel.getAttribute('data-placeholder') || '输入关键字搜索…';
        var list = document.createElement('div');
        list.className = 'searchable-list';
        list.style.display = 'none';

        // 原 select 保留在 wrapper 内并隐藏，表单提交仍以 select 的值为准
        sel.parentNode.insertBefore(wrapper, sel);
        wrapper.appendChild(input);
        wrapper.appendChild(list);
        sel.style.display = 'none';
        wrapper.appendChild(sel);

        var options = [];
        function loadOptions() {
            options = Array.prototype.map.call(sel.options, function(o) {
                return { value: o.value, text: o.textContent, sub: o.getAttribute('data-sub') || '' };
            });
        }
        loadOptions();

        function render(q) {
            q = (q || '').toLowerCase();
            list.innerHTML = '';
            var matched = options.filter(function(o) {
                return o.value !== '' && (q === '' ||
                    o.text.toLowerCase().indexOf(q) >= 0 ||
                    o.sub.toLowerCase().indexOf(q) >= 0);
            });
            if (!matched.length) {
                var empty = document.createElement('div');
                empty.className = 'searchable-item searchable-empty';
                empty.textContent = '无匹配结果';
                list.appendChild(empty);
                list.style.display = 'block';
                return;
            }
            matched.slice(0, 200).forEach(function(o) {
                var item = document.createElement('div');
                item.className = 'searchable-item';
                item.setAttribute('data-value', o.value);
                var main = document.createElement('span');
                main.textContent = o.text;
                item.appendChild(main);
                if (o.sub) {
                    var sub = document.createElement('small');
                    sub.textContent = o.sub;
                    item.appendChild(sub);
                }
                item.addEventListener('mousedown', function(e) { e.preventDefault(); pick(o); });
                list.appendChild(item);
            });
            list.style.display = 'block';
        }

        function pick(o) {
            // 远程结果可能不在原 select 中，补一个 option 才能正常提交
            var exists = Array.prototype.some.call(sel.options, function(op) { return op.value === o.value; });
            if (!exists) {
                var op = document.createElement('option');
                op.value = o.value;
                op.textContent = o.text;
                sel.appendChild(op);
                loadOptions();
            }
            sel.value = o.value;
            input.value = o.text;
            list.style.display = 'none';
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // 外部 JS 直接改 select 值（如重置表单）时同步输入框显示
        sel.addEventListener('change', function() {
            var cur = sel.options[sel.selectedIndex];
            input.value = (cur && cur.value) ? cur.textContent : '';
        });

        input.addEventListener('focus', function() { render(input.value); });
        input.addEventListener('input', function() {
            var q = input.value;
            if (sel.getAttribute('data-ajax')) {
                clearTimeout(input.__timer);
                input.__timer = setTimeout(function() {
                    ajaxSearchOptions(sel, q, function(items) { options = items; render(q); });
                }, 250);
            } else {
                render(q);
            }
        });
        input.addEventListener('blur', function() {
            // 只输入不选时回退显示已选值，避免界面显示与实际提交值不一致
            setTimeout(function() {
                var cur = sel.options[sel.selectedIndex];
                input.value = (cur && cur.value) ? cur.textContent : '';
            }, 150);
        });
        document.addEventListener('click', function(e) {
            if (!wrapper.contains(e.target)) { list.style.display = 'none'; }
        });

        var curOpt = sel.options[sel.selectedIndex];
        if (curOpt && curOpt.value) { input.value = curOpt.textContent; }
    }

    window.initSearchableSelects = function() {
        var nodes = document.querySelectorAll('select.searchable');
        Array.prototype.forEach.call(nodes, function(sel) {
            if (sel.getAttribute('data-searchable-init') === '1') return;
            sel.setAttribute('data-searchable-init', '1');
            buildSearchable(sel);
        });
    };

    // 计划下次跟进：快捷填充（days 天后 09:00；days 为 null 时清空）
    // 三个入口共用：客户列表跟进弹窗 / 客户详情页 / 跟进记录编辑弹窗
    window.setNextFollowQuick = function(target, days) {
        var el = (typeof target === 'string') ? document.querySelector(target) : target;
        if (!el) return;
        if (days === null || days === undefined || days === '') { el.value = ''; return; }
        var d = new Date();
        d.setDate(d.getDate() + parseInt(days, 10));
        var p = function(n) { return (n < 10 ? '0' : '') + n; };
        el.value = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T09:00';
    };

    // 计划下次跟进：数据库值 'Y-m-d H:i:s' -> datetime-local 需要的 'Y-m-dTH:i'
    // 不支持 datetime-local 的浏览器会退化成文本框，此时空格分隔的值用户也能看懂
    window.toDatetimeLocal = function(val) {
        if (!val) return '';
        return String(val).substring(0, 16).replace(' ', 'T');
    };

    // 计划下次跟进：显示用。历史数据只有日期（时间 00:00），只显示日期，避免看着像凌晨跟进
    window.fmtPlanFollow = function(val) {
        if (!val) return '';
        var s = String(val).replace('T', ' ');
        var d = s.substring(0, 10);
        var t = s.substring(11, 16);
        if (!t || t === '00:00') return d;
        return d + ' ' + t;
    };
})();
