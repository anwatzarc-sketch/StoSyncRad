# APPENDIX E — SEEDED CATEGORY TAXONOMY (COMPLETE COVERAGE)

Seeded in Setup Step 8 (full tree, by top-level group, or custom CSV). Codes are stable; names/labels are editable and localizable; every group ends with an `Other` leaf so no item lacks a precise placement. Clients may prune, rename or extend; archived categories keep history. Codes follow `TOP.SUB[.SUB]`.

| Code | Top-level group | Sub-categories (leaves unless noted) |
|---|---|---|
| RAW | Raw Materials | Metals & Alloys · Plastics & Polymers · Rubber · Glass · Wood & Timber · Paper & Pulp · Textile Fibres · Minerals & Ores · Agricultural Inputs · Chemical Feedstock · Other |
| CHEM | Chemicals | Industrial Chemicals · Laboratory Reagents · Solvents · Acids & Bases · Adhesives & Sealants · Paints & Coatings · Detergents (industrial) · Water Treatment · Agro-chemicals · Gases (industrial) · Other |
| FUEL | Fuels & Lubricants | Diesel · Petrol/Gasoline · Kerosene · LPG · Engine Oils · Hydraulic Fluids · Greases · Coolants · Other |
| PACK | Packaging Materials | Cartons & Boxes · Bags & Sacks · Films & Wraps · Pallets · Labels & Tags · Tapes · Strapping · Bottles & Containers · Protective Fill · Other |
| CONS | Consumables & Supplies | Workshop Consumables · Welding Consumables · Abrasives · Fasteners & Fixings · Filters · Batteries · Cleaning Consumables · Printing Consumables · Other |
| SPARE | Spare Parts | Mechanical Parts · Electrical Parts · Electronic Modules · Hydraulic & Pneumatic · Bearings & Seals · Belts & Chains · Gaskets & O-rings · Pumps & Valves · Vehicle Parts · HVAC Parts · Other |
| ELEC | Electrical Materials | Cables & Wires · Switches & Sockets · Circuit Breakers & Protection · Lighting & Lamps · Transformers · Panels & Enclosures · Conduits & Trunking · Connectors & Terminals · Generators & UPS · Solar Components · Other |
| MECH | Mechanical Materials | Pipes & Fittings · Structural Steel · Sheet & Plate · Bars & Profiles · Castings & Forgings · Springs · Couplings & Gears · Other |
| TOOL | Tools & Equipment | Hand Tools · Power Tools · Measuring Instruments · Lifting Equipment · Workshop Machinery · Laboratory Equipment · Garden & Grounds · Calibration Standards · Other |
| ITE | IT & Electronics | Computers & Laptops · Servers & Storage · Networking · Peripherals · Mobile Devices · Printers & Scanners · Barcode & RFID Hardware · Software Licences (non-stock) · Audio-Visual · Cables & Adapters · Other |
| OFF | Office Supplies | Paper & Stationery · Writing Instruments · Filing & Storage · Desk Accessories · Printer Supplies · Presentation Materials · Mailing & Shipping · Other |
| FURN | Furniture & Fixtures | Office Furniture · Shelving & Racking · Retail Fixtures · Seating · Storage Cabinets · Warehouse Fixtures · Other |
| SAFE | Safety & PPE | Head Protection · Eye & Face · Hearing · Respiratory · Hand Protection · Foot Protection · Body & Fall Protection · First Aid · Fire Safety · Signage · Other |
| CLEAN | Cleaning & Hygiene | Detergents & Disinfectants · Cleaning Tools · Paper Hygiene · Waste Management · Pest Control · Janitorial Equipment · Other |
| MED | Medical & Pharmaceutical | Medicines (Rx) · OTC Medicines · Vaccines & Biologicals (cold chain) · Medical Consumables · Diagnostics & Reagents · Medical Devices · Surgical Instruments · Nutrition & Supplements · Veterinary · Other |
| FOOD | Food & Beverage | Dry Goods & Grains · Fresh Produce · Dairy · Meat & Poultry · Fish & Seafood · Frozen · Bakery · Beverages (non-alcoholic) · Beverages (alcoholic) · Confectionery & Snacks · Spices & Condiments · Cooking Oils · Catering Supplies · Other |
| TEXT | Textiles & Apparel | Fabrics · Garments · Uniforms & Workwear · Footwear · Accessories · Home Textiles · Other |
| HOME | Household & Consumer Goods | Kitchenware · Appliances (small) · Appliances (large) · Personal Care · Baby Care · Home Décor · Toys & Games · Sports & Leisure · Other |
| CONST | Construction Materials | Cement & Aggregates · Bricks & Blocks · Roofing · Timber Products · Doors & Windows · Plumbing Fixtures · Tiles & Flooring · Insulation · Paints (construction) · Hardware & Ironmongery · Other |
| AGRI | Agricultural & Livestock | Seeds · Fertilizers · Pesticides · Animal Feed · Veterinary Supplies · Irrigation Equipment · Farm Tools · Other |
| AUTO | Vehicles & Transport | Vehicles (assets) · Tyres & Tubes · Batteries (vehicle) · Vehicle Accessories · Fleet Consumables · Other |
| FIN | Finished Goods | Own-Manufactured Products · Assembled Kits · Private Label · Trading Goods · Returned-Refurbished · Other |
| WIP | Work in Progress & Semi-Finished | Sub-assemblies · Semi-finished Components · Rework Items · Other |
| SERV | Services & Non-Stock (non-stockable) | Maintenance Services · Transport Services · Subscriptions · Fees & Charges · Other |

Coverage rules:
- Each top-level group is non-leaf; sub-categories are leaves (clients may add a third level, e.g. `ELEC.CABLE.LV`, `ELEC.CABLE.MV`).
- Category policy defaults seeded where obvious: `MED.*`, `FOOD.*`, `CHEM.*`, `AGRI.*` → `expiry_policy = tracked_required`, `removal_strategy = fefo`, `qc_policy = inspect_on_receipt`; `MED.VACC` → `quarantine_on_receipt`, storage category `cold_chain`; `ITE.*` high-value → `tracking_mode = serial` suggestion; `SERV.*` → `item_type = service`, non-stockable; `FUEL.*`, `CHEM.*` → `is_hazardous` default true.
- Seed is idempotent on `code`; names localizable via `enum_labels`-style category labels (`item_category_translations`).
- Reporting dataset `item_categories` exposes `full_path`, `depth`, `top_level_code` for grouping.
