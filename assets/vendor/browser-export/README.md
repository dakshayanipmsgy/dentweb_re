# Browser quotation export libraries

Runtime export must use local, pinned browser libraries and must not load a CDN.
Expected pinned files:

- Paged.js 0.4.3 (`paged.polyfill.min.js`)
- html2canvas 1.4.1 (`html2canvas.min.js`)
- jsPDF 2.5.1 (`jspdf.umd.min.js`)
- fflate 0.8.2 (`fflate.min.js`)

The client engine fails closed and shows an unsupported-browser/library message if these local files are absent.
