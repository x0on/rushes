const spanish = document.documentElement.lang === 'es';
const toggle = document.getElementById('brief-toggle');
const brief = document.getElementById('pilot-brief');
toggle.addEventListener('click', () => {
  const open = toggle.getAttribute('aria-expanded') !== 'true';
  toggle.setAttribute('aria-expanded', String(open));
  brief.hidden = !open;
  toggle.textContent = spanish ? (open ? 'Cerrar la guía del servicio' : 'Preparar una conversación sobre el servicio') : (open ? 'Close the service brief' : 'Prepare a service conversation');
  if (open) brief.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest' });
});
document.getElementById('download-brief').addEventListener('click', () => {
  const englishText = `AUDIOVISUAL INTELLIGENCE SERVICES\nDepartmental service discussion brief\nWorking descriptor | Rushes + StoryTown\n\nPurpose\nPreserve and unlock institutional audiovisual archives, and expand creative production with human-directed generative AI. Existing staff lead the work and approve every final output.\n\nService and workflow\nWe build the software and understand audiovisual work. Technical support accompanies the whole process. We can adapt workflows and develop specific capabilities, with scope and priorities agreed together. Rushes works within Adobe Premiere Pro and DaVinci Resolve.\n\nQuestions for the first conversation\n- Which archive collection would be most useful to make accessible?\n- Which upcoming film or campaign should we support first?\n- Who will coordinate the service and approve material, rights, and AI use?\n- What would count as a worthwhile result for the team?\n\nProposed scope\nYour archive, your production priorities, and your team’s working environment. Agree on duration, costs, continuous technical support, any tailored software development, data handling, compatibility, and access controls before starting.\n\nEvaluation\nReview time to find footage, reusable material found, production quality, and staff feedback together. Use those reviews to guide ongoing support and development.\n\nProcessing and governance\nAssess rights, factual accuracy, access controls, and any external AI processing. Rushes' local archive approach does not imply the same processing arrangements for StoryTown. Both tools are evolving; this is a departmental service proposal.\n`;
  const text = spanish ? `SERVICIOS DE INTELIGENCIA AUDIOVISUAL
Guía para conversar sobre el servicio departamental
Denominación provisional | Rushes + StoryTown

Objetivo
Preservar y aprovechar el archivo audiovisual institucional y ampliar la producción creativa con IA generativa dirigida por profesionales. El personal actual lidera el trabajo y aprueba cada resultado final.

Servicio y flujo de trabajo
Desarrollamos el software y conocemos el trabajo audiovisual. Ofrecemos soporte técnico durante todo el proceso. Podemos adaptar flujos de trabajo y crear funciones específicas, acordando juntos el alcance y las prioridades. Rushes funciona dentro de Adobe Premiere Pro y DaVinci Resolve.

Preguntas para la primera conversación
- ¿Qué colección del archivo sería más útil hacer accesible?
- ¿Qué próxima película o campaña deberíamos apoyar primero?
- ¿Quién coordinará el servicio y aprobará el material, los derechos y el uso de IA?
- ¿Qué resultado consideraría valioso el equipo?

Alcance propuesto
Su archivo, sus prioridades de producción y el entorno de trabajo de su equipo. Acordar duración, costes, soporte técnico continuo, posibles desarrollos a medida, tratamiento de datos, compatibilidad y controles de acceso antes de empezar.

Evaluación
Revisar juntos el tiempo de búsqueda, el material reutilizable localizado, la calidad de producción y la opinión del personal. Utilizar estas revisiones para orientar el soporte y el desarrollo continuos.

Tratamiento de la información y condiciones de uso
Evaluar los derechos, la exactitud de la información, los controles de acceso y cualquier procesamiento externo de IA. El enfoque local del archivo de Rushes no implica que StoryTown procese la información de la misma manera. Ambas herramientas están en evolución; esta es una propuesta de servicios departamentales.
` : englishText;
  const url = URL.createObjectURL(new Blob([text], {type:'text/plain;charset=utf-8'}));
  const link = document.createElement('a');
  link.href = url; link.download = spanish ? 'guia-servicio-departamental.txt' : 'departmental-service-discussion.txt';
  document.body.append(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  document.getElementById('download-status').textContent = spanish ? 'Guía descargada. No se ha enviado información.' : 'Discussion brief downloaded. No information was sent.';
});

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
