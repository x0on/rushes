const toggle = document.getElementById('brief-toggle');
const brief = document.getElementById('pilot-brief');
toggle.addEventListener('click', () => {
  const open = toggle.getAttribute('aria-expanded') !== 'true';
  toggle.setAttribute('aria-expanded', String(open));
  brief.hidden = !open;
  toggle.textContent = open ? 'Close the discussion brief' : 'Prepare a pilot conversation';
  if (open) brief.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest' });
});
document.getElementById('download-brief').addEventListener('click', () => {
  const text = `AUDIOVISUAL INTELLIGENCE SERVICES\nDepartmental pilot discussion brief\nWorking descriptor | Rushes + StoryTown\n\nPurpose\nPreserve and unlock institutional audiovisual archives, and expand creative production with human-directed generative AI. Existing staff lead the work and approve every final output.\n\nQuestions for the first conversation\n- Which archive collection would be most useful to make accessible?\n- What upcoming film or campaign could provide a practical test?\n- Who will lead the pilot and approve material, rights, and AI use?\n- What would count as a worthwhile result for the team?\n\nProposed scope\nOne department, one archive sample, one real project. Agree on duration, costs, staff support, data handling, compatibility, and access controls before starting.\n\nEvaluation\nCompare time to find footage, reusable material found, production quality, and staff feedback. Review the evidence before deciding whether to expand.\n\nProcessing and governance\nAssess rights, factual accuracy, access controls, and any external AI processing. Rushes' local archive approach does not imply the same processing arrangements for StoryTown. Both tools are evolving; this is a pilot proposal.\n`;
  const url = URL.createObjectURL(new Blob([text], {type:'text/plain;charset=utf-8'}));
  const link = document.createElement('a');
  link.href = url; link.download = 'departmental-pilot-discussion.txt';
  document.body.append(link); link.click(); link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  document.getElementById('download-status').textContent = 'Discussion brief downloaded. No information was sent.';
});
