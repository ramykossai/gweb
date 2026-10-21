// ============================
// HERBAL VITALY – main.js
// ============================

document.addEventListener('DOMContentLoaded', () => {

  // ── Navbar scroll effect ──
  const navbar = document.getElementById('navbar');
  window.addEventListener('scroll', () => {
    if (navbar) navbar.classList.toggle('scrolled', window.scrollY > 50);
  });

  // ── Mobile menu ──
  const menuToggle = document.getElementById('menuToggle');
  const mobileMenu = document.getElementById('mobileMenu');
  if (menuToggle && mobileMenu) {
    menuToggle.addEventListener('click', () => {
      mobileMenu.classList.toggle('open');
    });
    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => mobileMenu.classList.remove('open'));
    });
  }

  // ── Floating Particles ──
  const particlesContainer = document.getElementById('particles');
  const PARTICLE_COUNT = 30;
  if (particlesContainer) {
  for (let i = 0; i < PARTICLE_COUNT; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    const size = Math.random() * 3 + 1;
    p.style.cssText = `
      left: ${Math.random() * 100}%;
      width: ${size}px;
      height: ${size}px;
      animation-duration: ${Math.random() * 15 + 10}s;
      animation-delay: ${Math.random() * 15}s;
      opacity: ${Math.random() * 0.5 + 0.1};
    `;
    particlesContainer.appendChild(p);
  }
  }

  // ── Scroll Reveal ──
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
      }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

  // Add reveal class to sections
  const revealTargets = document.querySelectorAll(
    '.product-card, .ingredient-card, .bienfait-item, .usage-step, .temo-card, .contact-item'
  );
  revealTargets.forEach((el, i) => {
    el.classList.add('reveal');
    el.style.transitionDelay = `${i * 0.08}s`;
    revealObserver.observe(el);
  });

  // ── Active nav link ──
  const sections = document.querySelectorAll('section[id]');
  const navLinks = document.querySelectorAll('.nav-links a');

  const sectionObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        navLinks.forEach(link => {
          link.classList.remove('active');
          if (link.getAttribute('href') === '#' + entry.target.id) {
            link.classList.add('active');
          }
        });
      }
    });
  }, { threshold: 0.5 });

  sections.forEach(s => sectionObserver.observe(s));

  // ── Smooth CTA counter animation ──
  const counters = document.querySelectorAll('.stat-num');
  counters.forEach(counter => {
    const target = counter.textContent.replace(/\D/g, '');
    if (!target) return;
    const suffix = counter.textContent.replace(/[0-9]/g, '');
    const duration = 1500;
    const start = 0;
    const inc = target / (duration / 16);
    let current = start;
    const timer = setInterval(() => {
      current += inc;
      if (current >= target) {
        current = target;
        clearInterval(timer);
      }
      counter.textContent = Math.floor(current) + suffix;
    }, 16);
  });

  // ── Console branding ──
  console.log('%cHERBAL VITALY', 'color:#00ff88;font-size:24px;font-weight:bold;font-family:monospace');
  console.log('%cBURN FAST – Site développé pour Herbal Vitaly, Tunis', 'color:#7ab893;font-size:12px');

});
