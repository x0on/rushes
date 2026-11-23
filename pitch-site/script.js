const spanish = document.documentElement.lang === 'es';
const toggle = document.getElementById('brief-toggle');
const brief = document.getElementById('pilot-brief');
toggle.addEventListener('click', () => {
  const open = toggle.getAttribute('aria-expanded') !== 'true';
  toggle.setAttribute('aria-expanded', String(open));
  brief.hidden = !open;
  toggle.textContent = spanish ? (open ? 'Cerrar la guía de conversación' : 'Preparar la conversación sobre el piloto') : (open ? 'Close the discussion brief' : 'Prepare a pilot conversation');
  if (open) brief.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest' });
});
document.getElementById('download-brief').addEventListener('click', () => {
  const englishText = `AUDIOVISUAL INTELLIGENCE SERVICES\nDepartmental pilot discussion brief\nWorking descriptor | Rushes + StoryTown\n\nPurpose\nPreserve and unlock institutional audiovisual archives, and expand creative production with human-directed generative AI. Existing staff lead the work and approve every final output.\n\nQuestions for the first conversation\n- Which archive collection would be most useful to make accessible?\n- What upcoming film or campaign could provide a practical test?\n- Who will lead the pilot and approve material, rights, and AI use?\n- What would count as a worthwhile result for the team?\n\nProposed scope\nOne department, one archive sample, one real project. Agree on duration, costs, staff support, data handling, compatibility, and access controls before starting.\n\nEvaluation\nCompare time to find footage, reusable material found, production quality, and staff feedback. Review the evidence before deciding whether to expand.\n\nProcessing and governance\nAssess rights, factual accuracy, access controls, and any external AI processing. Rushes' local archive approach does not imply the same processing arrangements for StoryTown. Both tools are evolving; this is a pilot proposal.\n`;
  const text = spanish ? `SERVICIOS DE INTELIGENCIA AUDIOVISUAL
Guía para conversar sobre un piloto departamental
Denominación provisional | Rushes + StoryTown

Objetivo
Preservar y aprovechar el archivo audiovisual institucional y ampliar la producción creativa con IA generativa dirigida por profesionales. El personal actual lidera el trabajo y aprueba cada resultado final.

Preguntas para la primera conversación
- ¿Qué colección del archivo sería más útil hacer accesible?
- ¿Qué próxima película o campaña permitiría realizar una prueba práctica?
- ¿Quién dirigirá el piloto y aprobará el material, los derechos y el uso de IA?
- ¿Qué resultado consideraría valioso el equipo?

Alcance propuesto
Un departamento, una muestra del archivo y un proyecto real. Acordar duración, costes, acompañamiento del personal, tratamiento de datos, compatibilidad y controles de acceso antes de empezar.

Evaluación
Comparar el tiempo de búsqueda, el material reutilizable localizado, la calidad de producción y la opinión del personal. Revisar los resultados antes de ampliar el uso.

Tratamiento de la información y condiciones de uso
Evaluar los derechos, la exactitud de la información, los controles de acceso y cualquier procesamiento externo de IA. El enfoque local del archivo de Rushes no implica que StoryTown procese la información de la misma manera. Ambas herramientas están en evolución; esta es una propuesta de piloto.
` : englishText;
  const url = URL.createObjectURL(new Blob([text], {type:'text/plain;charset=utf-8'}));
  const link = document.createElement('a');
  link.href = url; link.download = spanish ? 'guia-piloto-departamental.txt' : 'departmental-pilot-discussion.txt';
  document.body.append(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  document.getElementById('download-status').textContent = spanish ? 'Guía descargada. No se ha enviado información.' : 'Discussion brief downloaded. No information was sent.';
});
