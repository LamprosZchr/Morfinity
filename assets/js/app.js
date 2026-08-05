document.querySelector('.nav-toggle')?.addEventListener('click', e => {
  const nav = document.querySelector('#nav'); const open = e.currentTarget.getAttribute('aria-expanded') === 'true';
  e.currentTarget.setAttribute('aria-expanded', String(!open)); nav.classList.toggle('open');
});
document.querySelectorAll('[data-confirm]').forEach(el => el.addEventListener('click', e => { if (!confirm(el.dataset.confirm)) e.preventDefault(); }));

