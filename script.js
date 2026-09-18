document.addEventListener('DOMContentLoaded', () => {
  const materialSelect = document.getElementById('material');
  const colorSelect = document.getElementById('color');
  
// once click a kind of material then will see prompt of color options.
  function updateColorOptions() {
    const selectedMaterial = materialSelect.value;
    const filamentColors = window.filamentColors || {};

    colorSelect.innerHTML = '';

    if (selectedMaterial && filamentColors[selectedMaterial]) {
      const defaultOption = document.createElement('option');
      defaultOption.value = "";
      defaultOption.textContent = "Select a color...";
      colorSelect.appendChild(defaultOption);

      filamentColors[selectedMaterial].forEach(color => {
        const option = document.createElement('option');
        option.value = color.toLowerCase();
        option.textContent = color;
        colorSelect.appendChild(option);
      });
    } else {
      const option = document.createElement('option');
      option.value = "any";
      option.textContent = selectedMaterial === 'Other' ? "Specify in notes" : "Any color";
      colorSelect.appendChild(option);
    }
  }

  // Listen for changes on the material dropdown
  if (materialSelect && colorSelect) {
    materialSelect.addEventListener('change', updateColorOptions);
    // Run immediately on page load in case a material is pre-selected
    updateColorOptions();
  }
});