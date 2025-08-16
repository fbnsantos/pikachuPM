// Constraints para garantir unicidade dos nós
CREATE CONSTRAINT FOR (m:Manufacturer) REQUIRE m.Manufacturer_ID IS UNIQUE;
CREATE CONSTRAINT FOR (s:Supplier) REQUIRE s.Supplier_ID IS UNIQUE;
CREATE CONSTRAINT FOR (p:Prototype) REQUIRE p.Prototype_ID IS UNIQUE;
CREATE CONSTRAINT FOR (c:Component) REQUIRE c.Component_ID IS UNIQUE;
CREATE CONSTRAINT FOR (a:Assembly) REQUIRE a.Assembly_ID IS UNIQUE;