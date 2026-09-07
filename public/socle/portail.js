// Portail d'acces de la plateforme statique.
//
// CE QUE C'EST, ET CE QUE CE N'EST PAS. C'est un filtre : il evite qu'un
// visiteur de passage arrive sur la plateforme sans savoir ce qu'il regarde, et
// il donne un cadre professionnel a l'entree. Ce n'est PAS une securite. Un
// site statique n'a pas de serveur pour verifier quoi que ce soit : la
// verification a lieu dans le navigateur du visiteur, et qui sait lire du
// JavaScript passe outre.
//
// Cela ne coute rien, parce qu'il n'y a rien a proteger : les donnees sont
// entierement synthetiques. Aucun compte reel, aucune personne reelle, aucun
// etablissement reel, et les fichiers de paiement produits ne peuvent atteindre
// aucune banque. La vraie porte -- verification serveur, session qui expire,
// blocage apres plusieurs essais -- garde l'application Symfony, la ou il y a
// un serveur pour la tenir.
//
// Le mot de passe n'est pas ecrit ici : seul le condensat SHA-256 du couple
// « identifiant:mot de passe » y figure. C'est une precaution de lecture, pas
// une barriere.

const CLE = 'suite-creances:portail';
const EMPREINTE = '54f9dae264912342bbd0549f6124a595e6a5120a57abaa3fb902cacd73db5535';

/** Le condensat du couple saisi, par l'API du navigateur. */
async function condensat(texte) {
  const octets = new TextEncoder().encode(texte);
  const brut = await crypto.subtle.digest('SHA-256', octets);
  return [...new Uint8Array(brut)].map((o) => o.toString(16).padStart(2, '0')).join('');
}

/**
 * Attend que le visiteur soit entre. Rend une promesse qui ne se resout
 * qu'une fois la porte franchie -- l'application n'est chargee qu'apres.
 */
export function franchir() {
  try {
    if (sessionStorage.getItem(CLE) === EMPREINTE) return Promise.resolve();
  } catch { /* navigation privee : on demande l'entree, simplement */ }

  const chargement = document.getElementById('chargement');
  if (chargement) chargement.hidden = true;

  const voile = document.createElement('div');
  voile.className = 'portail';
  voile.innerHTML = `
    <form class="portail__carte" id="portail-form" autocomplete="on">
      <div class="portail__filet"></div>
      <h1 class="portail__titre">Plateforme de démonstration</h1>
      <p class="portail__sous">Mémoire d'expertise comptable — optimisation du cycle
        créances clients d'un groupe de distribution automobile</p>

      <p class="portail__texte">L'accès est réservé. Les identifiants figurent dans le
        mémoire déposé.</p>

      <label class="portail__label" for="portail-id">Identifiant</label>
      <input class="portail__champ" id="portail-id" name="username" type="text"
             autocapitalize="none" spellcheck="false" required>

      <label class="portail__label" for="portail-mdp">Mot de passe</label>
      <input class="portail__champ" id="portail-mdp" name="password" type="password" required>

      <button class="portail__bouton" type="submit">Entrer</button>
      <p class="portail__erreur" id="portail-erreur" hidden>Identifiant ou mot de passe incorrect.</p>

      <p class="portail__note"><b>Toutes les données sont synthétiques.</b> Aucune donnée
        réelle, aucun compte réel, aucune personne réelle. Les fichiers de paiement produits
        sont des fichiers de démonstration : aucun ordre bancaire n'est transmis.</p>
    </form>`;
  document.body.appendChild(voile);

  const champId = voile.querySelector('#portail-id');
  const champMdp = voile.querySelector('#portail-mdp');
  const erreur = voile.querySelector('#portail-erreur');
  champId.focus();

  return new Promise((entre) => {
    let essais = 0;
    voile.querySelector('#portail-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const vu = await condensat(`${champId.value.trim()}:${champMdp.value}`);
      if (vu === EMPREINTE) {
        try { sessionStorage.setItem(CLE, EMPREINTE); } catch { /* la session vit en memoire */ }
        voile.remove();
        if (chargement) chargement.hidden = false;
        entre();
        return;
      }
      essais += 1;
      erreur.hidden = false;
      champMdp.value = '';
      champMdp.focus();
      // Une temporisation croissante : elle ne gene pas un lecteur legitime.
      voile.querySelector('#portail-form').style.pointerEvents = 'none';
      setTimeout(() => { voile.querySelector('#portail-form').style.pointerEvents = ''; },
        Math.min(2000, 250 * essais));
    });
  });
}
