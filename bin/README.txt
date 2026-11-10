NE PAS envoyer le dossier bin/python/ (des milliers de fichiers).

Dans bin/, envoyer SEULEMENT ces archives :
  yt-dlp-2026.pyz   ~3 Mo
  cpython.tar.gz    ~33 Mo   ← le serveur l’extrait tout seul
  deno.gz           ~40 Mo
  ffmpeg.gz         ~28 Mo

+ scripts/converter.py

Si FileZilla propose bin/python/ → ignore / exclure ce dossier.
Sur le serveur, tu peux supprimer bin/python/ s’il est à moitié uploadé ;
au prochain lancement le site recrée depuis cpython.tar.gz.