// Match your API path
const API_BASE = location.origin + '/isiqueue/api/';
const ENDPOINTS = { staff: API_BASE + 'staff.php' };

// Elements
const form  = document.getElementById('loginForm');
const email = document.getElementById('email');
const pass  = document.getElementById('password');
const statusEl = document.getElementById('loginStatus');

const togglePwd = document.getElementById('togglePwd');
const forgotLink = document.getElementById('forgotLink');
const forgotModal = document.getElementById('forgotModal');
const cancelForgot = document.getElementById('cancelForgot');
const forgotForm = document.getElementById('forgotForm');
const fpEmail = document.getElementById('fpEmail');
const forgotStatus = document.getElementById('forgotStatus');

// helpers
function show(el, ok, msg, ms=2600){
  if(!el) return;
  const okIcon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>`;
  const erIcon = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 6L6 18M6 6l12 12"/></svg>`;
  el.className = `form-status ${ok?'ok':'err'}`;
  el.innerHTML = `${ok?okIcon:erIcon} <span>${msg}</span>`;
  clearTimeout(el._t);
  el._t = setTimeout(()=>{ el.className='form-status'; el.innerHTML=''; }, ms);
}

// interactions
togglePwd?.addEventListener('click', ()=>{ pass.type = pass.type === 'password' ? 'text' : 'password'; });

// login submit
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  try{
    const res = await fetch(ENDPOINTS.staff, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ action:'login', email: email.value.trim(), password: pass.value })
    });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { throw new Error('Invalid server response'); }

    if(res.ok && data.ok){
      show(statusEl, true, 'Login successful!');
      localStorage.setItem('staff', JSON.stringify(data.staff));
      setTimeout(()=> location.href = './index.html', 800);
    }else{
      throw new Error(data.error || `HTTP ${res.status}`);
    }
  }catch(err){
    console.error(err);
    show(statusEl, false, err.message || 'Login failed', 3600);
  }
});

// forgot password modal
forgotLink?.addEventListener('click', (e)=>{ e.preventDefault(); forgotModal.classList.add('show'); fpEmail.value = email.value.trim(); });
cancelForgot?.addEventListener('click', ()=> forgotModal.classList.remove('show'));
forgotModal?.addEventListener('click', (e)=>{ if(e.target === forgotModal) forgotModal.classList.remove('show'); });

// forgot submit
forgotForm?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  try{
    const res = await fetch(ENDPOINTS.staff, {
      method:'POST',
      headers:{ 'Content-Type':'application/json' },
      body: JSON.stringify({ action:'reset_password', email: fpEmail.value.trim() })
    });
    const text = await res.text();
    let data; try { data = JSON.parse(text); } catch { throw new Error('Invalid server response'); }

    if(res.ok && data.ok){
      show(forgotStatus, true, `Temp password: ${data.temp_password}`, 6000);
    }else{
      throw new Error(data.error || `HTTP ${res.status}`);
    }
  }catch(err){
    console.error(err);
    show(forgotStatus, false, err.message || 'Reset failed', 3800);
  }
});
