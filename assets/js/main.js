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
        // 初始化AJAX导航
        initAjaxNav();
    });

    // ============ AJAX 导航系统 ============
    var isNavigating = false;

    function initAjaxNav() {
        // 拦截侧边栏导航链接
        document.querySelectorAll('.sidebar-nav a.nav-item').forEach(function(link) {
            link.addEventListener('click', function(e) {
                // 不拦截右键/中键/修饰键
                if (e.ctrlKey || e.metaKey || e.button !== 0) return;
                e.preventDefault();
                var url = link.getAttribute('href');
                if (!url) return;
                // 如果点击的是当前已激活的链接，不做任何事
                if (link.classList.contains('active')) return;
                // 移动端：点击导航链接后自动关闭侧边栏
                if (window.innerWidth <= 1024) toggleSidebar();
                navigateTo(url);
            });
        });

        // 监听浏览器前进/后退
        window.addEventListener('popstate', function(e) {
            navigateTo(location.href, true);
        });
    }

    function navigateTo(url, isPop) {
        if (isNavigating) return;
        isNavigating = true;

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
            var curWrapper = document.querySelector('.content-wrapper');
            if (!curWrapper) { window.location = url; return; }

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
                curWrapper.innerHTML = newWrapper.innerHTML;
            } else {
                window.location = url;
                return;
            }

            // 更新URL（popstate事件由浏览器触发，不需要pushState）
            if (!isPop) {
                history.pushState(null, '', url);
            }

            // 更新侧边栏激活状态
            updateActiveNav(url);

            // 执行内容中的脚本
            executeScripts(curWrapper);

            // 滚动到顶部
            window.scrollTo(0, 0);
        })
        .catch(function() {
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

    function executeScripts(container) {
        var scripts = container.querySelectorAll('script');
        scripts.forEach(function(oldScript) {
            var newScript = document.createElement('script');
            // 复制属性
            for (var i = 0; i < oldScript.attributes.length; i++) {
                newScript.setAttribute(oldScript.attributes[i].name, oldScript.attributes[i].value);
            }
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
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
        const container = document.getElementById('toast-container') || createToastContainer();
        const toast = document.createElement('div');
        const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
        const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
        toast.innerHTML = '<i class="fa-solid ' + (icons[type] || icons.info) + '"></i> ' + message;
        toast.style.cssText = 'background:white;color:#333;padding:10px 16px;border-radius:8px;margin-bottom:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:13px;display:flex;align-items:center;gap:8px;animation:slideIn 0.3s ease;border-left:3px solid ' + (colors[type] || colors.info) + ';';
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
        if (e.target.classList.contains('number-input')) {
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

})();
