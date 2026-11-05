---
paths:
  - public/css/pos.css
---

# Css

## Kerangka grid layar POS: jangan sembunyikan bagian dengan display: none
`.pos` adalah grid 5 baris (header, ribbons, main, bottom, fkeys) dengan area bernama. Setiap bagian wajib tetap menjadi item grid walau kosong (pita kosong: height 0, bukan display: none), jika tidak baris di bawahnya bergeser dan ringkasan/F-key membesar. Bagian baru harus diberi grid-area. Dijaga oleh resources/js/pos/layout.test.js.
