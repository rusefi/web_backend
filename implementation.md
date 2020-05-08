rusEFI online (REO) backend is tightly integrated with phpbb forum.
REO frontend is a TunerStudio plug-in.

# Authentication

REO token is visible to the user on his personal forum page. Users have a button to reset their REO token on their forum page.
User has to copy paste his token into plugin settings to establish connectivity.

# .ini workflow

.ini files are resolved based on signature using https://rusefi.com/online/ini/rusefi/ library 


# Domain Model

Users: ``user_id``

We use phpbb users.

Engine:



Vehicle: ``user_id, vehicle_name``
 
All and each vehicle is unique! Even if you have two Miatas, those are two different vehicles.


Engines: User,Make,Code,Displacement,Compression,Aspiration

Tune: User