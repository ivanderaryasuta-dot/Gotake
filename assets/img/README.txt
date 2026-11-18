WHERE IMAGES LIVE  (two folders, two jobs)
==========================================
assets/img/   STATIC images: they ship with the website and never change.
              logo.png            default logo (top bar + splash screen)
              Troli.jpg           picture on the Login / Register pages
              item-placeholder.svg  shown for an item that has no photo yet

uploads/      CHANGING images: everything uploaded from inside the website.
              uploads/items/<item id>/     item photos (Admin > Manage Items > Photos)
              uploads/loans/<loan id>/     pick-up and return photos
              uploads/branding/logo.*      a logo uploaded in Admin > Settings
                                           (wins over assets/img/logo.png)

Item photos are ONLY read from uploads/items/. There is no assets/img/items/ folder any more.
Make sure the uploads/ folder is writable by the web server.
