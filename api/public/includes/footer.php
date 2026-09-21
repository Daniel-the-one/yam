</section><!-- /.content -->
  </main><!-- /.main -->
</div><!-- /.app -->
<!-- CSRF auto-attach : ajoute le token à tous les fetch POST -->
<script>
(function(){
  var token = document.querySelector('meta[name="csrf-token"]');
  if (!token) return;
  var origFetch = window.fetch;
  window.fetch = function(url, opts){
    opts = opts || {};
    var m = (opts.method || 'GET').toUpperCase();
    if (m === 'POST' || m === 'PUT' || m === 'DELETE' || m === 'PATCH'){
      opts.headers = opts.headers || {};
      if (opts.headers instanceof Headers){
        opts.headers.set('X-CSRF-Token', token.content);
      } else {
        opts.headers['X-CSRF-Token'] = token.content;
      }
    }
    return origFetch.apply(this, arguments);
  };
})();
</script>
<!-- JS fusionné (app.js + push.js + incoming-call-listener.js) pour
     limiter les requêtes HTTP → rate-limit o2switch. -->
<script src="/assets/js/kondjipro.js?v=2"></script>
<!-- ── Écoute des appels entrants YAM (Pusher) ─────────────── -->
<script src="https://js.pusher.com/8.4/pusher.min.js"></script>
</body>
</html>
