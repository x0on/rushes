const spanish = document.documentElement.lang === 'es';
const storyTabs = [...document.querySelectorAll('.st-viewer [role="tab"]')];
function selectStoryTab(tab) {
  storyTabs.forEach(item => {
    const active = item === tab;
    item.setAttribute('aria-selected', String(active));
    item.tabIndex = active ? 0 : -1;
    document.getElementById(item.getAttribute('aria-controls')).hidden = !active;
  });
}
storyTabs.forEach((tab, index) => {
  tab.addEventListener('click', () => selectStoryTab(tab));
  tab.addEventListener('keydown', event => {
    let next;
    if (event.key === 'ArrowRight') next = (index + 1) % storyTabs.length;
    else if (event.key === 'ArrowLeft') next = (index + storyTabs.length - 1) % storyTabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = storyTabs.length - 1;
    else return;
    event.preventDefault(); selectStoryTab(storyTabs[next]); storyTabs[next].focus();
  });
});

const galleryItems = [
  ['storytown-production.jpg', 'Production timeline', 'Línea de tiempo de producción'],
  ['storytown-storyboard.jpg', 'Storyboard your next campaign', 'El storyboard de su próxima campaña'],
  ['storytown-characters.jpg', 'Characters, voices, and settings', 'Personajes, voces y escenarios'],
  ['storytown-writing.jpg', 'Writing and creative development', 'Guion y desarrollo creativo'],
  ['storytown-planning.jpg', 'Shot planning and staging', 'Planificación de planos y puesta en escena'],
  ['storytown-creative-tools.jpg', 'Generative media tools', 'Herramientas de medios generativos'],
];
const gallery = document.getElementById('image-gallery');
const galleryImage = document.getElementById('gallery-image');
let galleryIndex = 0;
let galleryOpener;
let previousOverflow;
function renderGallery() {
  const item = galleryItems[galleryIndex];
  const title = item[spanish ? 2 : 1];
  galleryImage.src = 'assets/' + item[0];
  galleryImage.alt = 'StoryTown — ' + title;
  document.getElementById('gallery-title').textContent = title;
  document.getElementById('gallery-count').textContent = `${galleryIndex + 1} / ${galleryItems.length}`;
}
function moveGallery(step) {
  galleryIndex = (galleryIndex + step + galleryItems.length) % galleryItems.length;
  renderGallery();
}
document.querySelectorAll('.st-viewer a[href]').forEach(link => {
  link.addEventListener('click', event => {
    event.preventDefault();
    galleryOpener = link;
    galleryIndex = galleryItems.findIndex(item => link.getAttribute('href') === 'assets/' + item[0]);
    if (link.getAttribute('href') === 'assets/storytown-canvas.jpg') {
      galleryIndex = galleryItems.findIndex(item => item[0] === 'storytown-creative-tools.jpg');
    }
    if (galleryIndex < 0) galleryIndex = 0;
    renderGallery();
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    gallery.showModal();
  });
});
gallery.querySelector('.gallery-close').addEventListener('click', () => gallery.close());
gallery.querySelector('.gallery-prev').addEventListener('click', () => moveGallery(-1));
gallery.querySelector('.gallery-next').addEventListener('click', () => moveGallery(1));
gallery.addEventListener('keydown', event => {
  if (event.key === 'ArrowLeft') { event.preventDefault(); moveGallery(-1); }
  if (event.key === 'ArrowRight') { event.preventDefault(); moveGallery(1); }
});
gallery.addEventListener('click', event => { if (event.target === gallery) gallery.close(); });
gallery.addEventListener('close', () => {
  document.body.style.overflow = previousOverflow || '';
  galleryOpener?.focus();
});
