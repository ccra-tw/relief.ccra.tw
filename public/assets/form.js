// 條件欄位：data-show="欄位=值"，該選項勾選時才顯示（伺服器端仍會驗證，這裡只管畫面）
(function () {
  function sync() {
    document.querySelectorAll('[data-show]').forEach(function (el) {
      var p = el.getAttribute('data-show').split('='), name = p[0], val = p.slice(1).join('=');
      var on = Array.prototype.some.call(document.querySelectorAll('input[name="' + name + '"],input[name="' + name + '[]"]'),
        function (i) { return i.checked && i.value === val; });
      el.style.display = on ? '' : 'none';
    });
  }
  document.addEventListener('change', sync);
  sync();
})();
