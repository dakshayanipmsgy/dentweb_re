(function () {
  const path = window.location.pathname.replace(/\/+$/, '') || '/index.php';
  const isHome = path === '' || path === '/' || path.endsWith('/index.php') || path.endsWith('/home.html');
  if (!isHome) return;

  function createEnhancedSections() {
    return `
      <section class="section energycare-injected revamp-hero-strip" data-energycare-home>
        <div class="container revamp-hero-grid">
          <div>
            <span class="revamp-kicker"><i class="fa-solid fa-bolt"></i> 20-year energy business model</span>
            <h2>From one-time solar EPC to lifetime EnergyCare.</h2>
            <p>Dakshayani Enterprises now positions itself around installation, maintenance, monitoring, upgrade, EV charging and solar material support — so every customer can stay connected beyond commissioning.</p>
            <div class="revamp-actions">
              <a class="btn btn-primary" href="/energycare.html"><i class="fa-solid fa-screwdriver-wrench"></i> Explore EnergyCare AMC</a>
              <a class="btn btn-secondary" href="/powerstore.html"><i class="fa-solid fa-boxes-stacked"></i> PowerStore Materials</a>
              <a class="btn btn-secondary" href="/installer-network.html"><i class="fa-solid fa-people-roof"></i> Installer Network</a>
            </div>
            <div class="revamp-metrics">
              <div class="revamp-metric"><strong>25 yrs</strong><span>Solar asset care mindset</span></div>
              <div class="revamp-metric"><strong>5 engines</strong><span>EPC, AMC, storage, EV, materials</span></div>
              <div class="revamp-metric"><strong>1 desk</strong><span>Lead, service and documentation support</span></div>
            </div>
          </div>
          <div class="revamp-panel">
            <h3>What customers can now ask us for</h3>
            <div class="revamp-panel-list">
              <div class="revamp-panel-item"><i class="fa-solid fa-solar-panel"></i><div><strong>New solar plant</strong><p>Residential, commercial, institutional and government EPC.</p></div></div>
              <div class="revamp-panel-item"><i class="fa-solid fa-stethoscope"></i><div><strong>Solar health check</strong><p>Generation, inverter, earthing, wiring and visible fault checks.</p></div></div>
              <div class="revamp-panel-item"><i class="fa-solid fa-car-battery"></i><div><strong>Battery / hybrid upgrade</strong><p>Critical load backup and future-ready storage planning.</p></div></div>
              <div class="revamp-panel-item"><i class="fa-solid fa-charging-station"></i><div><strong>EV charger integration</strong><p>Solar-linked charger planning for homes and commercial sites.</p></div></div>
            </div>
          </div>
        </div>
      </section>

      <section class="section energycare-injected">
        <div class="container">
          <div class="head">
            <span class="section-kicker">Business engines</span>
            <h2>One brand, multiple revenue lines</h2>
            <p>Public visitors see Dakshayani as more than an installer: a long-term energy partner with practical services after installation.</p>
          </div>
          <div class="revamp-card-grid">
            <article class="revamp-card"><i class="fa-solid fa-house-chimney"></i><h3>Solar EPC</h3><p>PM Surya Ghar, commercial, institutional, hybrid and government execution.</p></article>
            <article class="revamp-card"><i class="fa-solid fa-screwdriver-wrench"></i><h3>EnergyCare AMC</h3><p>Annual maintenance, solar health checks, service tickets and generation support.</p></article>
            <article class="revamp-card"><i class="fa-solid fa-car-battery"></i><h3>Storage & Hybrid</h3><p>Battery backup planning, hybrid upgrades and critical-load energy support.</p></article>
            <article class="revamp-card"><i class="fa-solid fa-charging-station"></i><h3>EV Charging</h3><p>EV charger consultation with solar and load-management thinking.</p></article>
            <article class="revamp-card"><i class="fa-solid fa-boxes-stacked"></i><h3>PowerStore</h3><p>Solar BOS, earthing, protection, wiring and material support for execution teams.</p></article>
            <article class="revamp-card"><i class="fa-solid fa-people-roof"></i><h3>Installer Network</h3><p>Train and coordinate local electricians, fitters and district partners without heavy fixed salary burden.</p></article>
          </div>
        </div>
      </section>

      <section class="section energycare-injected alt">
        <div class="container revamp-split">
          <div>
            <span class="section-kicker">EnergyCare packages</span>
            <h2>Recurring service for stable business and happier customers</h2>
            <p>Every installed system can become a long-term relationship through AMC, service visits and upgrade opportunities.</p>
            <ol class="revamp-flow">
              <li>Customer books health check or AMC from website / WhatsApp.</li>
              <li>Lead is captured through the existing website lead system.</li>
              <li>Team inspects site and updates customer with practical findings.</li>
              <li>AMC, cleaning, repair, battery or EV upgrade can be proposed.</li>
            </ol>
          </div>
          <div class="revamp-package-grid">
            <article class="revamp-package"><i class="fa-solid fa-clipboard-check"></i><h3>Health Check</h3><p>For existing solar owners needing a basic inspection.</p></article>
            <article class="revamp-package"><i class="fa-solid fa-shield-heart"></i><h3>Residential AMC</h3><p>Scheduled support for 1 kW to 10 kW rooftop systems.</p></article>
            <article class="revamp-package"><i class="fa-solid fa-building-user"></i><h3>Commercial AMC</h3><p>Service planning for schools, offices, pumps, hospitals and MSMEs.</p></article>
          </div>
        </div>
      </section>

      <section class="section energycare-injected revamp-cta-band">
        <div class="container revamp-split">
          <div>
            <span class="revamp-kicker">Connected to operations</span>
            <h2>Website enquiries continue into the portal workflow.</h2>
            <p>The homepage lead form remains connected with the existing public lead API, so new solar, AMC, battery, EV and material enquiries can enter the admin side for follow-up.</p>
          </div>
          <div class="revamp-card-actions">
            <a class="btn btn-primary" href="/contact.html">Send Enquiry</a>
            <a class="btn btn-secondary" href="/login.php">Open Login Portal</a>
            <a class="btn btn-secondary" href="/solar-and-finance.php">Solar & Finance</a>
          </div>
        </div>
      </section>
    `;
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('[data-energycare-home]')) return;
    document.body.classList.add('energy-home-enhanced');
    const anchor = document.querySelector('[data-home-sections]') || document.querySelector('#offers') || document.querySelector('main');
    if (!anchor) return;
    if (anchor.matches('main')) {
      anchor.insertAdjacentHTML('beforeend', createEnhancedSections());
    } else {
      anchor.insertAdjacentHTML('afterend', createEnhancedSections());
    }
  });
})();
