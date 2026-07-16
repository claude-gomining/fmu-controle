// Overlay "Aguarde" ao cadastrar/atualizar uma blueprint (busca síncrona no Canvas).
const overlay = document.getElementById('cv-overlay');

document.querySelectorAll('form[data-loading]').forEach((form) => {
  form.addEventListener('submit', () => {
    if (overlay) {
      overlay.hidden = false;
    }
  });
});

// "Selecionar todos" dentro de cada blueprint.
document.querySelectorAll('[data-select-all]').forEach((master) => {
  const form = master.closest('form');

  if (!form) {
    return;
  }

  master.addEventListener('change', () => {
    form
      .querySelectorAll('input[name="course_ids[]"]')
      .forEach((checkbox) => {
        checkbox.checked = master.checked;
      });
  });
});
