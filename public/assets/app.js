const selectAll = document.querySelector('[data-select-all]');

if (selectAll) {
  selectAll.addEventListener('change', () => {
    document
      .querySelectorAll('input[name="discipline_ids[]"]')
      .forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
  });
}
