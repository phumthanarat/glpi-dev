/**
 * IT Chat - floating chat widget (see ../setup.php).
 * All user content is rendered with textContent (never innerHTML).
 */
(function () {
    'use strict';

    if (window.self !== window.top || window.itchatLoaded || typeof $ === 'undefined') {
        return; // skip iframes/modals and double inclusion
    }
    window.itchatLoaded = true;

    const ROOT = (typeof CFG_GLPI !== 'undefined' ? CFG_GLPI.root_doc : '') + '/plugins/itchat/ajax/chat.php';
    const POLL_OPEN = 4000;
    const POLL_IDLE = 15000;
    const POLL_HIDDEN = 30000;
    const POLL_HIDDEN_TECH = 10000; // technicians get notified from background tabs, so poll a bit faster

    // Per-browser preference; storage can be unavailable (private mode), so never let it throw.
    const pref = {
        get: (k, d) => { try { const v = localStorage.getItem('itchat.' + k); return v === null ? d : v; } catch (e) { return d; } },
        set: (k, v) => { try { localStorage.setItem('itchat.' + k, v); } catch (e) { /* ignore */ } },
    };

    const state = {
        open: false,
        isTech: false,
        convId: 0,     // conversation shown in the panel (0 = user's current open one / none)
        conv: null,
        lastId: 0,     // last message id rendered
        timer: null,
        busy: false,
        seenUnread: null,   // tech: conv id -> unread count at the previous poll (null = first poll)
        canned: null,       // tech: quick replies, fetched once
        soundOn: pref.get('sound', '1') === '1',
        me: 0,
        filter: pref.get('filter', 'all'), // tech inbox tab: all | mine | waiting
        lastList: [],       // tech: inbox from the last poll
        searchResults: null, // tech: array while a search is active
        techs: null,        // tech: transfer targets, fetched once
        seenTech: null,     // tech: conv id -> assigned tech at the previous poll
        selfAssigned: new Set(), // tech: chats I claimed myself (no "transferred to you" alert)
    };

    // ---------- DOM ----------
    const el = (tag, cls, text) => {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    };

    const fab = el('button', 'itchat-fab');
    fab.type = 'button';
    fab.title = 'แชทกับ IT';
    const fabIcon = el('i', 'ti ti-message-circle');
    fab.appendChild(fabIcon);
    const badge = el('span', 'itchat-badge');
    badge.hidden = true;
    fab.appendChild(badge);

    const panel = el('div', 'itchat-panel');
    panel.hidden = true;

    const side = el('div', 'itchat-side');          // tech only: conversation list
    const sideHead = el('div', 'itchat-side-head');
    sideHead.appendChild(el('div', 'itchat-side-title', 'การสนทนา'));
    const search = el('input', 'form-control form-control-sm itchat-search');
    search.type = 'search';
    search.placeholder = 'ค้นหา ข้อความ / ชื่อ / #Ticket';
    const tabs = el('div', 'itchat-tabs');
    const TAB_LABELS = { all: 'ทั้งหมด', mine: 'ของฉัน', waiting: 'รอรับ' };
    const tabBtns = {};
    Object.keys(TAB_LABELS).forEach((k) => {
        const b = el('button', 'itchat-tab');
        b.type = 'button';
        b.dataset.filter = k;
        tabBtns[k] = b;
        tabs.appendChild(b);
    });
    sideHead.append(search, tabs);
    const list = el('div', 'itchat-list');
    side.append(sideHead, list);

    const main = el('div', 'itchat-main');
    const head = el('div', 'itchat-head');
    const headTitle = el('div', 'itchat-title', 'IT Support');
    const headSub = el('div', 'itchat-sub');
    const headText = el('div', 'itchat-headtext');
    headText.append(headTitle, headSub);
    const actions = el('div', 'itchat-actions');
    const closeBtn = el('button', 'itchat-iconbtn');
    closeBtn.type = 'button';
    closeBtn.title = 'ย่อ';
    closeBtn.appendChild(el('i', 'ti ti-x'));
    const soundBtn = el('button', 'itchat-iconbtn itchat-sound');
    soundBtn.type = 'button';
    soundBtn.hidden = true;
    const soundIcon = el('i');
    soundBtn.appendChild(soundIcon);
    const renderSoundBtn = () => {
        soundIcon.className = 'ti ' + (state.soundOn ? 'ti-bell-ringing' : 'ti-bell-off');
        soundBtn.title = state.soundOn ? 'ปิดเสียงแจ้งเตือนแชทใหม่' : 'เปิดเสียงแจ้งเตือนแชทใหม่';
    };
    renderSoundBtn();
    head.append(headText, actions, soundBtn, closeBtn);

    const body = el('div', 'itchat-body');
    const form = el('form', 'itchat-form');
    const input = el('textarea', 'form-control itchat-input');
    input.rows = 1;
    input.maxLength = 2000;
    input.placeholder = 'พิมพ์ข้อความ…';
    input.title = 'Enter ส่ง, Shift+Enter ขึ้นบรรทัดใหม่, Ctrl+V วางรูป';
    const sendBtn = el('button', 'btn btn-primary itchat-send');
    sendBtn.type = 'submit';
    sendBtn.appendChild(el('i', 'ti ti-send'));
    const attachBtn = el('button', 'btn btn-outline-secondary itchat-attach');
    attachBtn.type = 'button';
    attachBtn.title = 'แนบรูป/ไฟล์ (หรือวางรูปด้วย Ctrl+V)';
    attachBtn.appendChild(el('i', 'ti ti-paperclip'));
    const fileInput = el('input');
    fileInput.type = 'file';
    fileInput.hidden = true;
    const cannedBtn = el('button', 'btn btn-outline-secondary itchat-attach itchat-canned-btn');
    cannedBtn.type = 'button';
    cannedBtn.title = 'ข้อความสำเร็จรูป';
    cannedBtn.hidden = true;
    cannedBtn.appendChild(el('i', 'ti ti-message-2-bolt'));
    const cannedMenu = el('div', 'itchat-canned');
    cannedMenu.hidden = true;
    form.append(cannedBtn, attachBtn, fileInput, input, sendBtn, cannedMenu);

    main.append(head, body, form);
    panel.append(side, main);

    // ---------- helpers ----------
    const fmtTime = (s) => {
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return s;
        const today = new Date();
        const hm = d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
        return d.toDateString() === today.toDateString()
            ? hm
            : d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) + ' ' + hm;
    };

    const setBadge = (n) => {
        badge.hidden = !n;
        badge.textContent = n > 99 ? '99+' : String(n);
    };

    const scrollBottom = () => { body.scrollTop = body.scrollHeight; };

    const post = (data) => $.ajax({ url: ROOT, method: 'POST', data: data, dataType: 'json' });

    const fmtSize = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';

    // my message id -> its status element, for read receipts
    const receiptEls = new Map();
    const updateReceipts = (peerRead) => {
        receiptEls.forEach((node, id) => {
            const read = id <= peerRead;
            node.textContent = read ? 'อ่านแล้ว' : 'ส่งแล้ว';
            node.classList.toggle('read', read);
        });
    };

    const showError = (xhr) => {
        const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || 'เกิดข้อผิดพลาด ลองใหม่อีกครั้ง';
        if (typeof glpi_toast_error === 'function') {
            glpi_toast_error(msg);
        } else {
            alert(msg);
        }
    };

    const actionBtn = (icon, label, cls, onClick) => {
        const b = el('button', 'btn btn-sm ' + cls);
        b.type = 'button';
        b.appendChild(el('i', 'ti ' + icon + ' me-1'));
        b.appendChild(document.createTextNode(label));
        b.addEventListener('click', onClick);
        return b;
    };

    // ---------- rendering ----------
    const renderEmpty = () => {
        body.replaceChildren();
        const box = el('div', 'itchat-empty');
        box.appendChild(el('i', 'ti ti-messages'));
        box.appendChild(el('div', '', state.isTech
            ? 'เลือกการสนทนาทางซ้าย'
            : 'สวัสดีครับ มีอะไรให้ทีม IT ช่วยไหม? พิมพ์ข้อความด้านล่างได้เลย'));
        body.appendChild(box);
    };

    const renderFile = (f) => {
        if (!f.url) return el('div', 'itchat-file missing', f.name);
        const a = el('a', f.is_image ? 'itchat-image' : 'itchat-file');
        a.href = f.url;
        a.target = '_blank';
        a.rel = 'noopener';
        if (f.is_image) {
            const img = el('img');
            img.src = f.url;
            img.alt = f.name;
            img.loading = 'lazy';
            img.addEventListener('load', () => {
                if (body.scrollHeight - body.scrollTop - body.clientHeight < img.height + 120) scrollBottom();
            });
            a.appendChild(img);
        } else {
            a.appendChild(el('i', 'ti ti-file-download'));
            const info = el('span', 'itchat-file-info');
            info.appendChild(el('span', 'itchat-file-name', f.name));
            info.appendChild(el('span', 'itchat-file-size', fmtSize(f.size)));
            a.appendChild(info);
        }
        return a;
    };

    const appendMessages = (msgs) => {
        if (!msgs.length) return;
        const nearBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
        if (state.lastId === 0) body.replaceChildren();
        msgs.forEach((m) => {
            if (m.id <= state.lastId) return;
            state.lastId = m.id;
            if (m.system) {
                body.appendChild(el('div', 'itchat-system', m.content + ' · ' + fmtTime(m.date)));
                return;
            }
            const row = el('div', 'itchat-msg ' + (m.mine ? 'mine' : 'theirs'));
            if (!m.mine) row.appendChild(el('div', 'itchat-author', m.author));
            if (m.file) row.appendChild(renderFile(m.file));
            if (m.content) row.appendChild(el('div', 'itchat-bubble', m.content));
            const meta = el('div', 'itchat-time', fmtTime(m.date));
            if (m.mine) {
                const receipt = el('span', 'itchat-receipt');
                meta.prepend(receipt);
                receiptEls.set(m.id, receipt);
            }
            row.appendChild(meta);
            body.appendChild(row);
        });
        if (nearBottom || msgs.some((m) => m.mine)) scrollBottom();
    };

    const renderHead = () => {
        const c = state.conv;
        actions.replaceChildren();
        if (state.isTech) {
            headTitle.textContent = c ? c.user : 'IT Chat';
            headSub.textContent = c
                ? (c.status === 'closed'
                    ? 'ปิดแล้ว' + (c.satisfaction ? ' · คะแนน ' + '★'.repeat(c.satisfaction) : '')
                    : (c.tech ? 'ผู้รับผิดชอบ: ' + c.tech : 'รอรับเรื่อง'))
                : '';
        } else {
            headTitle.textContent = 'IT Support';
            headSub.textContent = c
                ? (c.status === 'closed' ? 'การสนทนาสิ้นสุดแล้ว' : (c.tech ? 'คุยกับ ' + c.tech : 'รอเจ้าหน้าที่ตอบกลับ…'))
                : 'ปกติตอบกลับภายในเวลาทำการ';
        }
        if (!c) return;

        if (c.tickets_id) {
            const a = el('a', 'btn btn-sm btn-outline-secondary');
            a.href = c.ticket_url;
            a.appendChild(el('i', 'ti ti-ticket me-1'));
            a.appendChild(document.createTextNode('#' + c.tickets_id));
            actions.appendChild(a);
        }
        if (c.status !== 'open') {
            if (!state.isTech) {
                actions.appendChild(actionBtn('ti-plus', 'เริ่มใหม่', 'btn-primary', () => {
                    state.convId = 0;
                    resetConv();
                    // show the fresh state right away instead of a blank panel until the poll returns
                    renderHead();
                    renderForm();
                    renderEmpty();
                    input.focus();
                    refresh();
                }));
            }
            return;
        }
        if (state.isTech && !c.tech_id) {
            actions.appendChild(actionBtn('ti-hand-grab', 'รับเรื่อง', 'btn-success', () => {
                state.selfAssigned.add(c.id);
                doAction('claim');
            }));
        }
        if (state.isTech) {
            actions.appendChild(actionBtn('ti-arrows-exchange', 'โอน', 'btn-outline-secondary', (e) => openTransferMenu(e.currentTarget)));
        }
        if (state.isTech && !c.tickets_id) {
            actions.appendChild(actionBtn('ti-ticket', 'เปิด Ticket', 'btn-outline-primary', () => openTicketDialog()));
        }
        actions.appendChild(actionBtn('ti-circle-check', 'ปิด', 'btn-outline-danger', () => {
            if (confirm('ปิดการสนทนานี้?')) doAction('close');
        }));
    };

    const renderForm = () => {
        const closed = state.conv && state.conv.status !== 'open';
        const noConvTech = state.isTech && !state.conv;
        form.hidden = !!(closed || noConvTech);
    };

    const inFilter = (c, f) => f === 'mine' ? c.tech_id === state.me
        : f === 'waiting' ? c.status === 'open' && !c.tech_id
        : true;

    const renderTabs = () => {
        Object.entries(tabBtns).forEach(([k, b]) => {
            const unread = state.lastList.filter((c) => c.unread > 0 && inFilter(c, k)).length;
            const count = k === 'waiting' ? state.lastList.filter((c) => inFilter(c, k)).length : unread;
            b.textContent = TAB_LABELS[k] + (count ? ' (' + count + ')' : '');
            b.classList.toggle('active', state.filter === k && state.searchResults === null);
        });
    };

    const renderList = (items) => {
        if (items) state.lastList = items;
        renderTabs();
        list.replaceChildren();
        let shown;
        if (state.searchResults !== null) {
            shown = state.searchResults;
            list.appendChild(el('div', 'itchat-list-note', shown.length ? 'ผลการค้นหา ' + shown.length + ' รายการ' : 'ไม่พบแชทที่ตรงกับคำค้น'));
        } else {
            shown = state.lastList.filter((c) => inFilter(c, state.filter));
            if (!shown.length) {
                list.appendChild(el('div', 'itchat-list-empty', {
                    all: 'ยังไม่มีการสนทนา', mine: 'ยังไม่มีแชทที่คุณรับผิดชอบ', waiting: 'ไม่มีแชทที่รอรับเรื่อง',
                }[state.filter]));
                return;
            }
        }
        shown.forEach((c) => {
            const it = el('button', 'itchat-item'
                + (c.id === state.convId ? ' active' : '')
                + (c.status !== 'open' ? ' closed' : ''));
            it.type = 'button';
            const top = el('div', 'itchat-item-top');
            top.appendChild(el('span', 'itchat-item-name', c.user));
            if (c.unread) top.appendChild(el('span', 'badge bg-red text-white', String(c.unread)));
            it.appendChild(top);
            it.appendChild(el('div', 'itchat-item-last', c.last_msg || ''));
            const meta = (c.status !== 'open' ? 'ปิดแล้ว' : (c.tech ? c.tech : 'รอรับเรื่อง'))
                + (c.satisfaction ? ' · ' + '★'.repeat(c.satisfaction) : '')
                + (c.tickets_id ? ' · #' + c.tickets_id : '');
            it.appendChild(el('div', 'itchat-item-meta' + (!c.tech_id && c.status === 'open' ? ' waiting' : ''),
                meta + ' · ' + fmtTime(c.date_mod)));
            it.addEventListener('click', () => {
                if (state.convId === c.id) return;
                state.convId = c.id;
                resetConv();
                refresh();
            });
            list.appendChild(it);
        });
    };

    // ---------- transfer (tech) ----------
    let transferMenu = null;
    const closeTransferMenu = () => {
        if (transferMenu) transferMenu.remove();
        transferMenu = null;
    };
    const openTransferMenu = (anchor) => {
        if (transferMenu) return closeTransferMenu();
        const convId = state.convId;
        const show = () => {
            closeTransferMenu();
            transferMenu = el('div', 'itchat-menu');
            transferMenu.appendChild(el('div', 'itchat-menu-title', 'โอนแชทให้'));
            const current = state.conv ? state.conv.tech_id : 0;
            const targets = (state.techs || []).filter((t) => t.id !== current);
            if (!targets.length) transferMenu.appendChild(el('div', 'itchat-menu-empty', 'ไม่มีช่างคนอื่น'));
            targets.forEach((t) => {
                const b = el('button', 'itchat-menu-item', t.name);
                b.type = 'button';
                b.addEventListener('click', () => {
                    closeTransferMenu();
                    post({ action: 'transfer', conv: convId, users_id: t.id }).done(() => {
                        if (typeof glpi_toast_info === 'function') glpi_toast_info('โอนแชทให้ ' + t.name + ' แล้ว');
                        refresh();
                    }).fail(showError);
                });
                transferMenu.appendChild(b);
            });
            const r = anchor.getBoundingClientRect();
            const pr = main.getBoundingClientRect();
            transferMenu.style.top = (r.bottom - pr.top + 4) + 'px';
            transferMenu.style.right = Math.max(8, pr.right - r.right) + 'px';
            main.appendChild(transferMenu);
        };
        if (state.techs) return show();
        $.ajax({ url: ROOT, method: 'GET', dataType: 'json', data: { action: 'techs' } })
            .done((r) => { state.techs = r.techs || []; show(); })
            .fail(showError);
    };

    // ---------- satisfaction rating (requester, closed chat) ----------
    const rateBar = el('div', 'itchat-rate');
    rateBar.hidden = true;
    main.insertBefore(rateBar, form); // between the messages and the input
    const renderRating = () => {
        const c = state.conv;
        const want = !state.isTech && c && c.status === 'closed' && !c.satisfaction && state.lastId > 0;
        rateBar.hidden = !want;
        if (!want || rateBar.dataset.conv === String(c.id)) return;
        rateBar.dataset.conv = String(c.id);
        rateBar.replaceChildren(el('div', 'itchat-rate-title', 'ให้คะแนนการช่วยเหลือครั้งนี้'));
        const stars = el('div', 'itchat-stars');
        const btns = [];
        for (let v = 1; v <= 5; v++) {
            const b = el('button', 'itchat-star', '★');
            b.type = 'button';
            b.title = v + ' ดาว';
            b.addEventListener('mouseenter', () => btns.forEach((x, i) => x.classList.toggle('on', i < v)));
            b.addEventListener('click', () => {
                btns.forEach((x) => { x.disabled = true; });
                post({ action: 'rate', conv: c.id, value: v }).done(() => {
                    if (typeof glpi_toast_info === 'function') glpi_toast_info('ขอบคุณสำหรับคะแนนครับ');
                    refresh();
                }).fail((xhr) => {
                    btns.forEach((x) => { x.disabled = false; });
                    showError(xhr);
                });
            });
            btns.push(b);
            stars.appendChild(b);
        }
        stars.addEventListener('mouseleave', () => btns.forEach((x) => x.classList.remove('on')));
        rateBar.appendChild(stars);
    };

    // ---------- search (tech) ----------
    let searchTimer = null;
    let searchSeq = 0;
    const runSearch = () => {
        const q = search.value.trim();
        if (q.length < 2) {
            state.searchResults = null;
            renderList();
            return;
        }
        const seq = ++searchSeq;
        $.ajax({ url: ROOT, method: 'GET', dataType: 'json', data: { action: 'search', q: q } }).done((r) => {
            if (seq !== searchSeq) return; // a newer search is on its way
            state.searchResults = r.results || [];
            renderList();
        });
    };

    // ---------- "เปิด Ticket" dialog (tech) ----------
    let dialog = null;
    const closeTicketDialog = () => {
        if (dialog) dialog.remove();
        dialog = null;
    };

    const field = (label, control, hint) => {
        const wrap = el('div', 'mb-2');
        const l = el('label', 'form-label mb-1', label);
        wrap.append(l, control);
        if (hint) wrap.appendChild(el('div', 'form-hint', hint));
        return wrap;
    };

    const option = (value, label, selected) => {
        const o = el('option', '', label);
        o.value = String(value);
        o.selected = !!selected;
        return o;
    };

    const openTicketDialog = () => {
        closeTicketDialog();
        const convId = state.convId;
        $.ajax({ url: ROOT, method: 'GET', dataType: 'json', data: { action: 'ticketform', conv: convId } })
            .fail(showError)
            .done((f) => {
                if (convId !== state.convId) return;
                dialog = el('div', 'itchat-modal');
                const card = el('form', 'itchat-modal-card');
                card.appendChild(el('div', 'itchat-modal-title', 'เปิด Ticket จากแชทนี้'));

                const name = el('input', 'form-control form-control-sm');
                name.maxLength = 250;
                name.value = f.title;
                name.required = true;

                // No default type: the technician has to choose Incident or Request.
                const type = el('select', 'form-select form-select-sm');
                type.required = true;
                const placeholder = option('', '— เลือกประเภท —', true);
                placeholder.disabled = true;
                type.appendChild(placeholder);
                f.types.forEach((t) => type.appendChild(option(t.value, t.label, false)));

                const cat = el('select', 'form-select form-select-sm');
                const groupInfo = el('div', 'itchat-group-info');
                const g = f.requester_group;
                const fillCategories = () => {
                    const keep = cat.value;
                    if (type.value === '') {
                        cat.replaceChildren(option(0, '— เลือกประเภทก่อน —', true));
                        cat.disabled = true;
                        groupInfo.hidden = true;
                        return;
                    }
                    cat.disabled = false;
                    const isIncident = type.value === String(f.types[0].value);
                    cat.replaceChildren(option(0, '— ไม่ระบุ —', true));
                    f.categories
                        .filter((c) => (isIncident ? c.incident : c.request))
                        .forEach((c) => cat.appendChild(option(c.id, c.name, String(c.id) === keep)));

                    // What will happen with the approval rule for this requester.
                    groupInfo.hidden = false;
                    groupInfo.className = 'itchat-group-info';
                    if (isIncident) {
                        groupInfo.textContent = g ? 'กลุ่มผู้แจ้ง: ' + g.name : 'ผู้ใช้ไม่มีกลุ่ม';
                    } else if (!g) {
                        groupInfo.classList.add('warn');
                        groupInfo.textContent = '⚠ ผู้ใช้ไม่มีกลุ่ม: Request นี้จะไม่ถูกส่งให้หัวหน้าอนุมัติ';
                    } else if (!g.has_manager) {
                        groupInfo.classList.add('warn');
                        groupInfo.textContent = '⚠ กลุ่ม ' + g.name + ' ไม่มีหัวหน้า: Request นี้จะไม่ถูกส่งอนุมัติ';
                    } else {
                        groupInfo.classList.add('ok');
                        groupInfo.textContent = 'กลุ่มผู้แจ้ง: ' + g.name + ' · Request จะถูกส่งให้หัวหน้ากลุ่มอนุมัติ';
                    }
                };
                fillCategories();
                type.addEventListener('change', fillCategories);

                const urg = el('select', 'form-select form-select-sm');
                f.urgencies.forEach((u) => urg.appendChild(option(u.value, u.label, u.value === 3)));

                card.append(
                    field('ชื่อเรื่อง', name),
                    field('ประเภท', type),
                    groupInfo,
                    field('หมวดหมู่', cat, 'ทีมผู้ดูแลและ SLA กำหนดตาม Business rules ที่เปิดใช้อยู่ (Setup > Rules)'),
                    field('ความเร่งด่วน', urg)
                );

                const btns = el('div', 'itchat-modal-actions');
                const cancel = el('button', 'btn btn-sm btn-outline-secondary', 'ยกเลิก');
                cancel.type = 'button';
                cancel.addEventListener('click', closeTicketDialog);
                const submit = el('button', 'btn btn-sm btn-primary');
                submit.type = 'submit';
                submit.appendChild(el('i', 'ti ti-ticket me-1'));
                submit.appendChild(document.createTextNode('สร้าง Ticket'));
                btns.append(cancel, submit);
                card.appendChild(btns);

                card.addEventListener('submit', (e) => {
                    e.preventDefault();
                    submit.disabled = true;
                    state.selfAssigned.add(convId); // creating the ticket assigns me if nobody had it
                    post({
                        action: 'toticket',
                        conv: convId,
                        name: name.value,
                        type: type.value,
                        itilcategories_id: cat.value,
                        urgency: urg.value,
                    }).done((r) => {
                        closeTicketDialog();
                        if (typeof glpi_toast_info === 'function') {
                            glpi_toast_info('สร้าง Ticket #' + r.tickets_id + ' แล้ว');
                        }
                        refresh();
                    }).fail((xhr) => {
                        submit.disabled = false;
                        showError(xhr);
                    });
                });
                dialog.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeTicketDialog(); });
                dialog.addEventListener('click', (e) => { if (e.target === dialog) closeTicketDialog(); });

                dialog.appendChild(card);
                main.appendChild(dialog);
                name.focus();
                name.select();
            });
    };

    const resetConv = () => {
        closeTicketDialog();
        closeTransferMenu();
        state.conv = null;
        state.lastId = 0;
        receiptEls.clear();
        body.replaceChildren();
    };

    // ---------- technician alerts: sound + browser notification + tab title ----------
    const baseTitle = document.title;
    let audioCtx = null;
    const beep = () => {
        if (!state.soundOn) return;
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.18].forEach((delay, i) => {
                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.frequency.value = i ? 1175 : 880;
                gain.gain.setValueAtTime(0.0001, audioCtx.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.15, audioCtx.currentTime + delay + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + delay + 0.16);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(audioCtx.currentTime + delay);
                osc.stop(audioCtx.currentTime + delay + 0.17);
            });
        } catch (e) { /* audio blocked until the user interacts with the page: ignore */ }
    };

    const askNotificationPermission = () => {
        if ('Notification' in window && Notification.permission === 'default') {
            try { Notification.requestPermission(); } catch (e) { /* ignore */ }
        }
    };

    const alertNewMessages = (list) => {
        const prev = state.seenUnread;
        const prevTech = state.seenTech;
        state.seenUnread = new Map(list.map((c) => [c.id, c.unread]));
        state.seenTech = new Map(list.map((c) => [c.id, c.tech_id]));
        if (prev === null) return; // first poll after page load: just remember
        const fresh = list.filter((c) => c.unread > (prev.get(c.id) || 0));
        // chats someone else just handed to me
        const handed = list.filter((c) => c.tech_id === state.me && prevTech.has(c.id)
            && prevTech.get(c.id) !== state.me && !state.selfAssigned.has(c.id));
        handed.forEach((c) => fresh.includes(c) || fresh.push(Object.assign({}, c, { handedOver: true })));
        if (!fresh.length) return;
        beep();
        const lookingAtIt = !document.hidden && state.open;
        if (lookingAtIt || !('Notification' in window) || Notification.permission !== 'granted') return;
        fresh.slice(0, 3).forEach((c) => {
            try {
                const n = new Notification('💬 ' + (c.handedOver ? 'ได้รับโอนแชทจาก ' : c.status === 'open' && !c.tech_id ? 'แชทใหม่จาก ' : 'ข้อความจาก ') + c.user, {
                    body: c.last_msg || '',
                    tag: 'itchat-' + c.id,
                });
                n.onclick = () => {
                    window.focus();
                    state.convId = c.id;
                    resetConv();
                    toggle(true);
                    n.close();
                };
            } catch (e) { /* some browsers only allow notifications from a service worker */ }
        });
    };

    const renderCanned = () => {
        cannedMenu.replaceChildren();
        (state.canned || []).forEach((text) => {
            const b = el('button', 'itchat-canned-item', text);
            b.type = 'button';
            b.addEventListener('click', () => {
                input.value = input.value.trim() ? input.value.replace(/\s*$/, ' ') + text : text;
                cannedMenu.hidden = true;
                autoGrow();
                input.focus();
            });
            cannedMenu.appendChild(b);
        });
        if (!cannedMenu.childElementCount) {
            cannedMenu.appendChild(el('div', 'itchat-canned-empty', 'ยังไม่มีข้อความสำเร็จรูป (ตั้งได้ที่ Setup > Plugins > IT Chat)'));
        }
    };

    // ---------- data ----------
    const schedule = () => {
        clearTimeout(state.timer);
        const hidden = state.isTech ? POLL_HIDDEN_TECH : POLL_HIDDEN;
        const delay = document.hidden ? hidden : (state.open ? POLL_OPEN : POLL_IDLE);
        state.timer = setTimeout(refresh, delay);
    };

    function refresh() {
        if (state.busy) return schedule();
        state.busy = true;
        const reqConv = state.convId;
        $.ajax({
            url: ROOT,
            method: 'GET',
            dataType: 'json',
            data: { action: 'poll', conv: reqConv, after: state.lastId, open: state.open ? 1 : 0, canned: state.canned === null ? 1 : 0 },
        }).done((r) => {
            if (reqConv !== state.convId) return; // user switched conversation meanwhile
            state.isTech = r.is_tech;
            state.me = r.me;
            panel.classList.toggle('is-tech', r.is_tech);
            side.hidden = !r.is_tech;
            soundBtn.hidden = !r.is_tech;
            cannedBtn.hidden = !r.is_tech;
            setBadge(r.unread);
            if (r.is_tech) {
                if (Array.isArray(r.canned)) {
                    state.canned = r.canned;
                    renderCanned();
                }
                alertNewMessages(r.list || []);
                document.title = (r.unread ? '(' + r.unread + ') ' : '') + baseTitle;
            }

            if (!r.is_tech && r.conv && !state.convId) {
                state.convId = r.conv.id; // pin to it so a later close still shows the transcript
            }
            if (r.conv && state.conv && r.conv.id !== state.conv.id) resetConv();
            state.conv = r.conv;

            if (state.open) {
                if (r.is_tech) renderList(r.list || []);
                renderHead();
                renderForm();
                if (!r.conv) {
                    renderEmpty();
                } else {
                    appendMessages(r.messages || []);
                    updateReceipts(r.peer_read || 0);
                }
                renderRating();
            }
        }).always(() => {
            state.busy = false;
            schedule();
        });
    }

    const doAction = (action) => {
        post({ action: action, conv: state.convId }).done((r) => {
            if (action === 'toticket' && r.ticket_url && typeof glpi_toast_info === 'function') {
                glpi_toast_info('สร้าง Ticket #' + r.tickets_id + ' แล้ว');
            }
            refresh();
        }).fail(showError);
    };

    const send = () => {
        const text = input.value.trim();
        if (!text || sendBtn.disabled) return;
        sendBtn.disabled = true;
        if (state.isTech && state.convId) state.selfAssigned.add(state.convId);
        post({ action: 'send', conv: state.convId, content: text }).done((r) => {
            input.value = '';
            autoGrow();
            if (state.convId !== r.conv) {
                state.convId = r.conv;
                resetConv();
            }
            refresh();
        }).fail(showError).always(() => {
            sendBtn.disabled = false;
            input.focus();
        });
    };

    // Files are sent as their own message right away (like LINE); typed text stays in the box.
    const sendFile = (file) => {
        if (!file || attachBtn.disabled) return;
        if (state.conv && state.conv.status !== 'open' && state.convId) return;
        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('conv', state.convId);
        fd.append('file', file, file.name || ('screenshot-' + Date.now() + '.png'));
        attachBtn.disabled = true;
        attachBtn.classList.add('uploading');
        $.ajax({ url: ROOT, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
            .done((r) => {
                if (state.convId !== r.conv) {
                    state.convId = r.conv;
                    resetConv();
                }
                refresh();
            })
            .fail(showError)
            .always(() => {
                attachBtn.disabled = false;
                attachBtn.classList.remove('uploading');
                fileInput.value = '';
            });
    };

    const autoGrow = () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    };

    // ---------- events ----------
    const toggle = (open) => {
        state.open = open;
        panel.hidden = !open;
        fab.classList.toggle('active', open);
        fabIcon.className = open ? 'ti ti-x' : 'ti ti-message-circle';
        if (open) {
            if (state.isTech) askNotificationPermission(); // needs a user gesture: this click
            if (!state.conv && !state.isTech) renderEmpty();
            refresh();
            setTimeout(() => input.focus(), 50);
        }
    };

    fab.addEventListener('click', () => toggle(!state.open));
    closeBtn.addEventListener('click', () => toggle(false));
    Object.values(tabBtns).forEach((b) => b.addEventListener('click', () => {
        state.filter = b.dataset.filter;
        pref.set('filter', state.filter);
        search.value = '';
        state.searchResults = null;
        renderList();
    }));
    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 300);
    });
    search.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            search.value = '';
            runSearch();
        }
    });
    document.addEventListener('click', (e) => {
        if (transferMenu && !transferMenu.contains(e.target) && !e.target.closest('.itchat-actions')) closeTransferMenu();
    });
    soundBtn.addEventListener('click', () => {
        state.soundOn = !state.soundOn;
        pref.set('sound', state.soundOn ? '1' : '0');
        renderSoundBtn();
        if (state.soundOn) beep();
    });
    cannedBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        cannedMenu.hidden = !cannedMenu.hidden;
    });
    document.addEventListener('click', (e) => {
        if (!cannedMenu.hidden && !cannedMenu.contains(e.target)) cannedMenu.hidden = true;
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
    input.addEventListener('input', autoGrow);
    attachBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => sendFile(fileInput.files[0]));
    input.addEventListener('paste', (e) => {
        const item = Array.from((e.clipboardData || {}).items || []).find((i) => i.kind === 'file');
        if (item) {
            e.preventDefault();
            sendFile(item.getAsFile());
        }
    });
    main.addEventListener('dragover', (e) => { if (!form.hidden) { e.preventDefault(); main.classList.add('dragging'); } });
    main.addEventListener('dragleave', () => main.classList.remove('dragging'));
    main.addEventListener('drop', (e) => {
        main.classList.remove('dragging');
        if (form.hidden || !e.dataTransfer.files.length) return;
        e.preventDefault();
        sendFile(e.dataTransfer.files[0]);
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            send();
        }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    // GLPI's ITIL forms (ticket/problem/change) pin their Add / Save buttons in a footer bar at
    // the bottom of the window, right where the chat button sits. Lift the button and the panel
    // above that bar whenever it is on screen.
    const DEFAULT_FAB_BOTTOM = 24;
    const avoidFooter = () => {
        let lift = DEFAULT_FAB_BOTTOM;
        document.querySelectorAll('#itil-footer, .itil-footer').forEach((f) => {
            const r = f.getBoundingClientRect();
            if (r.height > 0 && r.top < window.innerHeight && r.bottom > window.innerHeight - 90) {
                lift = Math.max(lift, window.innerHeight - r.top + 12);
            }
        });
        const bottom = lift + 'px';
        if (fab.style.bottom !== bottom) {
            fab.style.bottom = bottom;
            panel.style.bottom = (lift + 68) + 'px';
            panel.style.maxHeight = 'calc(100vh - ' + (lift + 96) + 'px)';
        }
    };

    $(function () {
        document.body.append(fab, panel);
        avoidFooter();
        window.addEventListener('resize', avoidFooter);
        window.addEventListener('scroll', avoidFooter, { passive: true });
        setInterval(avoidFooter, 1000); // footers can appear later (tabs loaded by AJAX)
        // Deep link from the Google Chat notification: ?itchat=<conversation id>
        const deepLink = parseInt(new URLSearchParams(window.location.search).get('itchat') || '', 10);
        if (deepLink > 0) {
            state.convId = deepLink;
            toggle(true);
        } else {
            refresh();
        }
    });
})();
