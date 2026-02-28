const API_URL = './user_api.php';
let me = null, profiles = [], _cbRes = null;

// ─── BOOT ─────────────────────────────────────────────────────
(async () => {
  try {
    const r = await api('/auth/me');
    if (r.success) { me = r.user; enterApp(); }
    else goScreen('sLogin');
  } catch { goScreen('sLogin'); }
})();

function enterApp() {
  goScreen('sApp');
  setSbUser();
  loadProfiles();
}

// ─── AUTH ──────────────────────────────────────────────────────
async function doLogin() {
  const email = g('lEmail'), pass = g('lPass');
  clearF(['lf1','lf2']);
  G('loginAlert').style.display = 'none';
  if (!email) return fe('lf1','lEmailE','Введите email');
  if (!pass)  return fe('lf2','lPassE', 'Введите пароль');
  loading('loginBtn', true, 'Вхожу...');
  try {
    const r = await api('/auth/login','POST',{email, password: pass});
    if (r.success) { me = r.user; enterApp(); }
    else {
      const e = r.errors || {};
      if (e.email)   fe('lf1','lEmailE', e.email);
      if (e.password) fe('lf2','lPassE', e.password);
      if (e.general)  showAlert('loginAlert', e.general);
    }
  } catch { showAlert('loginAlert','Ошибка соединения'); }
  loading('loginBtn', false, 'Войти');
}

async function doRegister() {
  const name = g('rName'), email = g('rEmail'), pass = g('rPass');
  clearF(['rf1','rf2','rf3']);
  G('regAlert').style.display = 'none';
  if (!name)  return fe('rf1','rNameE', 'Введите имя');
  if (!email) return fe('rf2','rEmailE','Введите email');
  if (!pass)  return fe('rf3','rPassE', 'Мин. 8 символов');
  loading('regBtn', true, 'Создаю...');
  try {
    const r = await api('/auth/register','POST',{name, email, password: pass});
    if (r.success) { me = r.user; enterApp(); }
    else {
      const e = r.errors || {};
      if (e.name)     fe('rf1','rNameE', e.name);
      if (e.email)    fe('rf2','rEmailE',e.email);
      if (e.password) fe('rf3','rPassE', e.password);
      if (e.general)  showAlert('regAlert', e.general);
    }
  } catch { showAlert('regAlert','Ошибка соединения'); }
  loading('regBtn', false, 'Создать аккаунт');
}

async function doLogout() {
  await api('/auth/logout','POST').catch(() => {});
  me = null; profiles = [];
  goScreen('sLogin');
}

// ─── PROFILES ─────────────────────────────────────────────────
async function loadProfiles() {
  try {
    const r = await api('/profiles');
    if (r.success) {
      profiles = r.profiles || [];
      renderToken();
      renderStats();
      renderDash();
      renderList();
      const b = G('sbBadge');
      if (profiles.length) { b.textContent = profiles.length; b.style.display = ''; }
      else b.style.display = 'none';
    }
  } catch {}
}

function renderToken() {
  const t = me?.api_token || '—';
  G('heroToken').textContent = t;
  G('settingsToken').textContent = t;
}

function renderStats() {
  G('stTotal').textContent  = profiles.length;
  G('stActive').textContent = profiles.filter(p => p.is_active == 1).length;
  G('stTypes').textContent  = new Set(profiles.map(p => p.messenger_type)).size;
}

function renderDash() {
  const el = G('dashProfiles'), sl = profiles.slice(0, 4);
  if (!sl.length) {
    el.innerHTML = `<div class="empty" style="padding:24px 0">
      <div style="font-size:12px;color:var(--ink3)">Нет профилей — <a href="#" onclick="openNewProfile()">добавить первый</a></div>
    </div>`;
    return;
  }
  el.innerHTML = sl.map(profileCard).join('');
}

function renderList() {
  const el = G('profilesList');
  if (!profiles.length) {
    el.innerHTML = `<div class="empty">
      <span class="empty-ico">◈</span>
      <div class="empty-t">Нет профилей</div>
      <div class="empty-s">Добавьте профиль мессенджера — Max, Telegram Bot или Telegram User.<br>Каждый профиль — отдельный аккаунт мессенджера.</div>
      <button class="btn btn-primary" style="margin-top:18px" onclick="openNewProfile()">+ Добавить профиль</button>
    </div>`;
    return;
  }
  el.innerHTML = profiles.map(profileCard).join('');
}

function profileCard(p) {
  const m = mInfo(p.messenger_type);
  const sess = p.session;
  const sessHtml = sess ? sessionBadge(sess) : '';
  const sid = sess && sess.session_id ? sess.session_id : '';
  const needAuth = p.messenger_type === 'telegram_user' && sess && sess.status !== 'authorized' && sid;
  return `<div class="pc">
    <div class="pc-icon" style="${m.bg}">${m.ico}</div>
    <div class="pc-info">
      <div class="pc-name">${esc(p.name)}</div>
      <div class="pc-meta">
        <span class="badge ${m.bc}">${m.lbl}</span>
        <span class="badge ${p.is_active == 1 ? 'bg-green' : 'bg-red'}">${p.is_active == 1 ? 'Активен' : 'Отключён'}</span>
        ${sessHtml}
      </div>
    </div>
    <div class="pc-actions">
      ${needAuth ? `<button class="btn btn-primary btn-sm" onclick="openQR('${sid}',event)">Авторизовать</button>` : ''}
      <button class="btn btn-dark btn-sm" onclick="openEdit(${p.id})">Изменить</button>
    </div>
  </div>`;
}

function sessionBadge(sess) {
  if (sess.status === 'authorized') {
    const name = [sess.account_first_name, sess.account_last_name].filter(Boolean).join(' ');
    const user = sess.account_username ? `@${sess.account_username}` : (name || '');
    return `<span class="badge bg-green">✓ ${esc(user) || 'Авторизован'}</span>`;
  }
  return `<span class="badge bg-red">Не авторизован</span>`;
}

function openQR(sessionId, e) {
  if (e) e.stopPropagation();
  window.open(`/public/telegram/qr_auth.php?session_id=${encodeURIComponent(sessionId)}`, '_blank', 'width=520,height=700');
  setTimeout(loadProfiles, 5000);
  setTimeout(loadProfiles, 15000);
  setTimeout(loadProfiles, 30000);
}

// ─── NEW PROFILE MODAL ────────────────────────────────────────

function openNewProfile() {
  // Сбрасываем скрытый input типа
  G('npType').value = '';
  // Снимаем выделение с карточек мессенджеров
  document.querySelectorAll('.mp-card').forEach(c => c.classList.remove('selected'));
  // Сбрасываем остальные поля
  G('npName').value = '';
  G('npToken').value = '';
  G('npHint').style.display = 'none';
  G('npTokenF').style.display = 'none';
  G('npTokenE').style.display = 'none';
  openM('mNew');
}

// Вызывается при клике на карточку мессенджера
function pickMessenger(type, el) {
  document.querySelectorAll('.mp-card').forEach(c => c.classList.remove('selected'));
  el.classList.add('selected');
  G('npType').value = type;
  onTypeChange();
}

function onTypeChange() {
  const t = G('npType').value;
  const H = {
    max: {
      lbl: 'API токен Max',
      ph: 'Вставьте токен из кабинета Max',
      hint: '🔑 Личный кабинет Max → Профиль → Токен API'
    },
    telegram_bot: {
      lbl: 'Bot Token',
      ph: '123456789:ABCdef...',
      hint: '🤖 Получите токен у @BotFather в Telegram → /newbot'
    },
    telegram_user: {
      hint: '👤 Telegram User работает через MadelineProto. Токен не нужен — авторизация происходит через QR-код после создания профиля.'
    },
  };
  const info = H[t];
  if (!t || !info) { G('npHint').style.display = 'none'; G('npTokenF').style.display = 'none'; return; }
  G('npHint').textContent = info.hint;
  G('npHint').style.display = 'block';
  if (t === 'telegram_user') {
    G('npTokenF').style.display = 'none';
  } else {
    G('npTokenL').textContent = info.lbl;
    G('npToken').placeholder = info.ph;
    G('npTokenF').style.display = 'block';
  }
}

async function submitNew() {
  const type = G('npType').value, name = g('npName'), token = g('npToken');
  G('npTokenE').style.display = 'none';
  if (!type)  return toast('Выберите тип мессенджера','err');
  if (!name)  return toast('Введите название профиля','err');
  if (['max','telegram_bot'].includes(type) && !token) {
    G('npTokenE').textContent = 'Токен обязателен';
    G('npTokenE').style.display = 'block';
    return;
  }
  try {
    const r = await api('/profiles','POST',{messenger_type: type, name, token: token || null});
    if (r.success) { toast('Профиль создан!','ok'); closeM('mNew'); loadProfiles(); }
    else toast(r.errors?.general || r.errors?.token || r.error || 'Ошибка','err');
  } catch { toast('Ошибка соединения','err'); }
}

async function openEdit(id) {
  const r = await api('/profiles/' + id);
  if (!r.success) { toast('Не удалось загрузить','err'); return; }
  const p = r.profile, m = mInfo(p.messenger_type);
  G('mEditTitle').textContent = p.name;
  G('mEditDel').onclick = () => deleteProfile(id);

  let sessHtml = '';
  if (p.messenger_type === 'telegram_user') {
    const s = p.session;
    const authorized = s && s.status === 'authorized';
    const statusText = authorized ? '✓ Telegram авторизован' : '⚠ Telegram не авторизован';
    const accountLine = s && s.account_first_name
      ? `<div style="color:var(--ink2);font-size:12px;margin-top:3px">${esc(s.account_first_name)} ${esc(s.account_last_name||'')} ${s.account_username ? '@'+esc(s.account_username) : ''}</div>`
      : '';
    const authBtn = (!authorized && s && s.session_id)
      ? `<button onclick="openQR('${s.session_id}',event)" class="btn btn-primary btn-sm" style="margin-top:12px">Авторизовать через QR</button>`
      : '';
    sessHtml = `<div class="ib ${authorized ? 'success' : 'warn'}" style="margin-top:4px">
      <div style="font-weight:600">${statusText}</div>
      ${accountLine}${authBtn}
    </div>`;
  }

  const tokenHtml = p.messenger_type !== 'telegram_user'
    ? `<div class="mf"><label>${p.messenger_type === 'max' ? 'API токен Max' : 'Bot Token'} <span style="color:var(--ink3);font-weight:400;text-transform:none">(оставьте пустым, чтобы не менять)</span></label><input type="text" id="eToken" placeholder="Новый токен..."></div>`
    : '';

  G('mEditBody').innerHTML = `
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;padding:16px;background:var(--surface);border:1px solid var(--border);border-radius:10px">
      <div style="width:46px;height:46px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:22px;${m.bg}">${m.ico}</div>
      <div>
        <div style="font-weight:600;font-size:15px">${esc(p.name)}</div>
        <div style="margin-top:5px;display:flex;gap:5px">
          <span class="badge ${m.bc}">${m.lbl}</span>
          <span class="badge ${p.is_active == 1 ? 'bg-green' : 'bg-red'}">${p.is_active == 1 ? 'Активен' : 'Отключён'}</span>
        </div>
      </div>
    </div>
    <div class="mf"><label>Название</label><input type="text" id="eName" value="${esc(p.name)}"></div>
    ${tokenHtml}
    ${sessHtml}
    <div class="mf"><label>Статус</label><select id="eActive">
      <option value="1" ${p.is_active == 1 ? 'selected' : ''}>Активен</option>
      <option value="0" ${p.is_active == 0 ? 'selected' : ''}>Отключён</option>
    </select></div>
    <button class="btn btn-primary" onclick="saveEdit(${id})">Сохранить изменения</button>`;
  openM('mEdit');
}

async function saveEdit(id) {
  const name = g('eName'), token = g('eToken'), active = G('eActive')?.value;
  if (!name) return toast('Введите название','err');
  const data = {name, is_active: parseInt(active)};
  if (token) data.token = token;
  const r = await api('/profiles/' + id,'PATCH', data);
  if (r.success) { toast('Сохранено','ok'); closeM('mEdit'); loadProfiles(); }
  else toast(r.error || 'Ошибка','err');
}

async function deleteProfile(id) {
  const ok = await confirm2('Удалить профиль?','Это действие нельзя отменить. Webhook будет автоматически отписан.');
  if (!ok) return;
  const r = await api('/profiles/' + id,'DELETE');
  if (r.success) { toast('Профиль удалён','ok'); closeM('mEdit'); loadProfiles(); }
  else toast('Ошибка','err');
}

// ─── TOKEN ─────────────────────────────────────────────────────
async function copyToken() {
  const t = me?.api_token;
  if (!t) return;
  try { await navigator.clipboard.writeText(t); toast('Токен скопирован!','ok'); }
  catch { prompt('Скопируйте токен:', t); }
}

async function regenToken() {
  const ok = await confirm2('Перегенерировать токен?','Старый токен сразу перестанет работать. Нужно вставить новый токен в настройки Bitrix24.');
  if (!ok) return;
  const r = await api('/auth/regen-token','POST');
  if (r.success) { me.api_token = r.token; renderToken(); toast('Новый токен создан!','ok'); }
  else toast('Ошибка','err');
}

// ─── SETTINGS ─────────────────────────────────────────────────
function loadSettings() {
  if (!me) return;
  G('sName').value  = me.name  || '';
  G('sEmail').value = me.email || '';
}

async function saveName() {
  const name = g('sName');
  if (!name) return toast('Введите имя','err');
  const r = await api('/auth/profile','PATCH',{name});
  if (r.success) { me = r.user; setSbUser(); toast('Сохранено','ok'); }
  else toast(r.errors?.name || 'Ошибка','err');
}

async function savePass() {
  const cur = G('curPass').value, next = G('newPass').value;
  if (!cur || !next) return toast('Заполните оба поля','err');
  const r = await api('/auth/profile','PATCH',{current_password: cur, new_password: next});
  if (r.success) { G('curPass').value = ''; G('newPass').value = ''; toast('Пароль изменён','ok'); }
  else toast(r.errors?.current_password || r.errors?.new_password || 'Ошибка','err');
}

// ─── FAQ ───────────────────────────────────────────────────────
function toggleFaq(el) {
  const item = el.closest('.faq-item');
  const isOpen = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(i => {
    i.classList.remove('open');
    i.querySelector('.faq-a').style.maxHeight = '0';
  });
  if (!isOpen) {
    item.classList.add('open');
    const a = item.querySelector('.faq-a');
    a.style.maxHeight = a.scrollHeight + 'px';
  }
}

// ─── NAV ───────────────────────────────────────────────────────
function nav(page, btn) {
  document.querySelectorAll('.sb-item').forEach(b => b.classList.remove('on'));
  document.querySelectorAll('.page').forEach(p => p.classList.remove('on'));
  btn?.classList.add('on');
  G('p' + page)?.classList.add('on');
  if (page === 'Settings') loadSettings();
}

function stab(id, btn) {
  const pg = btn.closest('.page') || btn.closest('.mb');
  pg.querySelectorAll('.tab').forEach(b => b.classList.remove('on'));
  pg.querySelectorAll('.tp').forEach(p => p.classList.remove('on'));
  btn.classList.add('on');
  G(id)?.classList.add('on');
}

// ─── HELPERS ───────────────────────────────────────────────────
function setSbUser() {
  if (!me) return;
  G('sbName').textContent  = me.name || me.email;
  G('sbEmail').textContent = me.email;
  G('sbAvatar').textContent = (me.name || me.email || '?')[0].toUpperCase();
}

function mInfo(t) {
  return ({
    max:          { ico:'🟥', bg:'background:rgba(239,68,68,.12)',   lbl:'Max',          bc:'bg-max'   },
    telegram_bot: { ico:'🤖', bg:'background:rgba(6,182,212,.1)',    lbl:'Telegram Bot', bc:'bg-tgbot'  },
    telegram_user:{ ico:'👤', bg:'background:rgba(167,139,250,.1)',  lbl:'Telegram User',bc:'bg-tgu'   },
  })[t] || { ico:'💬', bg:'', lbl: t, bc:'bg-amber' };
}

async function api(path, method = 'GET', body = null) {
  const o = { method, headers: {'Content-Type':'application/json'} };
  if (body) o.body = JSON.stringify(body);
  const r = await fetch(API_URL + '?_path=' + encodeURIComponent(path), o);
  return r.json();
}

function goScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  G(id)?.classList.add('active');
}
function openM(id)  { G(id)?.classList.add('open'); }
function closeM(id) { G(id)?.classList.remove('open'); }
function G(id)  { return document.getElementById(id); }
function g(id)  { return G(id)?.value?.trim() || ''; }
function esc(s) { return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fe(fid, eid, msg) { G(fid)?.classList.add('bad'); const e = G(eid); if(e){ e.textContent = msg; e.style.display = 'block'; } }
function clearF(ids) { ids.forEach(id => G(id)?.classList.remove('bad')); document.querySelectorAll('.ferr').forEach(e => { e.style.display = 'none'; e.textContent = ''; }); }
function showAlert(id, msg) { const e = G(id); if(e){ e.textContent = msg; e.style.display = 'block'; } }
function loading(id, on, label) { const b = G(id); if(!b) return; b.disabled = on; b.innerHTML = on ? `<span class="spin"></span> ${label}` : label; }
let _tt;
function toast(msg, type = '') {
  const el = G('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  clearTimeout(_tt);
  _tt = setTimeout(() => el.className = '', 2800);
}
function confirm2(title, text) {
  return new Promise(res => {
    _cbRes = res;
    G('cbTitle').textContent = title;
    G('cbText').textContent = text;
    G('confirmBox').classList.add('open');
  });
}
function cbDone(v) { G('confirmBox').classList.remove('open'); if(_cbRes){ _cbRes(v); _cbRes = null; } }

document.querySelectorAll('.mb').forEach(b => b.addEventListener('click', e => { if(e.target === b) b.classList.remove('open'); }));