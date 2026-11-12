#!/usr/bin/env python3
"""Create a fictional fleet dataset for local QA demonstrations only."""
import argparse, datetime, random, sqlite3, subprocess, shutil, os
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
DB=Path(os.environ.get('AVTOPARKI_DATA_DIR', ROOT/'data'))/'avtoparki.sqlite'
parser=argparse.ArgumentParser()
parser.add_argument('--force',action='store_true',help='Replace an existing demo database')
args=parser.parse_args()
if DB.exists() and not args.force:
    raise SystemExit('Database already exists; refusing to overwrite. Use --force ONLY for disposable demo data.')
if not shutil.which('php'):
    raise SystemExit('PHP CLI with PDO SQLite is required. Install it before running this script.')
probe=subprocess.run(['php','-r','exit(extension_loaded("pdo_sqlite") ? 0 : 1);'],capture_output=True,text=True)
if probe.returncode:
    raise SystemExit('PHP PDO SQLite is required. Enable it before running this script.')
if DB.exists():DB.unlink()
for suffix in ('-wal', '-shm'):
    Path(str(DB)+suffix).unlink(missing_ok=True)
DB.parent.mkdir(parents=True,exist_ok=True)
subprocess.run(['php','-r','require $argv[1]; avtoparki_db();',str(ROOT/'config.php')],cwd=ROOT,check=True)
con=sqlite3.connect(DB)
con.execute('PRAGMA foreign_keys=ON')
random.seed(20261013)
for name,rate in [('Sedan',11),('SUV',15),('Van',9.8)]:con.execute('INSERT OR IGNORE INTO fuel(type_name,litres) VALUES(?,?)',(name,rate))
categories=dict(con.execute('SELECT type_name,id_pk FROM fuel'))
vehicles=[('Toyota','Corolla'),('Honda','Civic'),('Ford','Focus'),('Hyundai','Elantra'),('Kia','Sportage'),('Toyota','RAV4'),('Nissan','Qashqai'),('Volkswagen','Golf'),('Skoda','Octavia'),('Mazda','CX-5'),('Ford','Transit'),('Mercedes-Benz','Sprinter'),('Renault','Kangoo'),('Peugeot','Partner'),('Honda','CR-V'),('Subaru','Forester'),('Suzuki','Vitara'),('Toyota','Prius'),('BMW','320i'),('Audi','A4')]
for i,(make,model) in enumerate(vehicles,1):
    fuel_type='Diesel' if i%4==0 else 'Petrol'
    category=categories['Van' if i in (11,12,13,14) else ('SUV' if i in (5,6,7,10,15,16,17) else 'Sedan')]
    con.execute('INSERT INTO vehicle(make,model,plate_number,type_id,fuel_kind,initial_odometer,odometer) VALUES(?,?,?,?,?,?,?)',(make,model,f'DM-{i:03d}-QA',category,fuel_type,round(20000+i*3250,2),round(20000+i*3250,2)))
cities=['Tbilisi','Batumi','Kutaisi','Rustavi','Gori','Telavi','Mtskheta','Borjomi','Zugdidi','Poti']
for city in cities:con.execute('INSERT OR IGNORE INTO location_city(name) VALUES(?)',(city,))
for i in range(1,41):
    city=cities[(i-1)%len(cities)]
    name=f'Demo Depot → {city} / Route {i:02d}'
    con.execute('INSERT INTO route(route_name,city,address,kilometres,origin_label) VALUES(?,?,?,?,?)',(name,city,f'Demo Address {i}',round(8+i*3.75,2),'Demo Depot'))
for i in range(180):
    vid=random.randint(1,20);rid=random.randint(1,40)
    km=con.execute('SELECT kilometres FROM route WHERE id_pk=?',(rid,)).fetchone()[0]
    rate,kind=con.execute('SELECT f.litres,v.fuel_kind FROM vehicle v JOIN fuel f ON v.type_id=f.id_pk WHERE v.id_pk=?',(vid,)).fetchone()
    date=(datetime.date(2026,10,1)-datetime.timedelta(days=random.randint(0,270))).isoformat()
    con.execute('INSERT INTO history(vehicle_id,route_id,kilometres,fuel_calculated,fuel_kind,date) VALUES(?,?,?,?,?,?)',(vid,rid,km,round(km*rate/100,2),kind,date))
    con.execute('UPDATE vehicle SET odometer=ROUND(odometer+?,2) WHERE id_pk=?',(km,vid))
# The app initializes the same admin account for empty and seeded installations.
username=os.environ.get('FLEET_INITIAL_ADMIN_USERNAME') or 'admin'
con.commit()
assert con.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
assert not con.execute('PRAGMA foreign_key_check').fetchall()
print('Created fictional demo data: 20 vehicles, 40 routes, 180 trips.')
print('Local demo admin username:',username)
print('Local demo admin password:', 'from FLEET_INITIAL_ADMIN_PASSWORD' if os.environ.get('FLEET_INITIAL_ADMIN_PASSWORD') else 'demoadmin')
con.close()
