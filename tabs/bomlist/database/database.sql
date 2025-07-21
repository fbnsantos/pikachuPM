CREATE TABLE IF NOT EXISTS T_Manufacturer (
    Manufacturer_ID INT AUTO_INCREMENT PRIMARY KEY,
    Denomination VARCHAR(255) NOT NULL,
    Origin_Country VARCHAR(100),
    Website VARCHAR(255),
    Contacts TEXT,
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Updated_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS T_Supplier (
    Supplier_ID INT AUTO_INCREMENT PRIMARY KEY,
    Denomination VARCHAR(255) NOT NULL,
    Origin_Country VARCHAR(100),
    Website VARCHAR(255),
    Contacts TEXT,
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Updated_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS T_Prototype (
    Prototype_ID INT AUTO_INCREMENT PRIMARY KEY,
    Name VARCHAR(255) NOT NULL,
    Version VARCHAR(50) DEFAULT '1.0',
    Description TEXT,
    Status ENUM('Development', 'Testing', 'Production', 'Archived') DEFAULT 'Development',
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Updated_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS T_Component (
    Component_ID INT AUTO_INCREMENT PRIMARY KEY,
    Denomination VARCHAR(255) NOT NULL,
    Manufacturer_ID INT DEFAULT 1,
    Manufacturer_ref VARCHAR(100),
    Supplier_ID INT,
    Supplier_ref VARCHAR(100),
    General_Type VARCHAR(100),
    Price DECIMAL(10,2),
    Acquisition_Date DATE,
    Notes_Description TEXT,
    Stock_Quantity INT DEFAULT 0,
    Min_Stock INT DEFAULT 0,
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Updated_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (Manufacturer_ID) REFERENCES T_Manufacturer(Manufacturer_ID) ON DELETE SET NULL,
    FOREIGN KEY (Supplier_ID) REFERENCES T_Supplier(Supplier_ID) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS T_Assembly (
    Assembly_ID INT AUTO_INCREMENT PRIMARY KEY,
    Prototype_ID INT NOT NULL,
    Assembly_Designation VARCHAR(255) NOT NULL,

    Component_Father_ID INT DEFAULT NULL,
    Component_Child_ID INT DEFAULT NULL,
    Component_Father_Quantity INT NOT NULL DEFAULT 0,
    Component_Child_Quantity INT NOT NULL DEFAULT 0,

    Assembly_Father_ID INT DEFAULT NULL,
    Assembly_Child_ID INT DEFAULT NULL,
    Assembly_Father_Quantity INT NOT NULL DEFAULT 0,
    Assembly_Child_Quantity INT NOT NULL DEFAULT 0,
    Assembly_Level INT DEFAULT 0,

    Notes TEXT,
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (Component_Father_ID) REFERENCES T_Component(Component_ID) ON DELETE CASCADE,
    FOREIGN KEY (Component_Child_ID) REFERENCES T_Component(Component_ID) ON DELETE CASCADE,
    FOREIGN KEY (Assembly_Father_ID) REFERENCES T_Assembly(Assembly_ID) ON DELETE CASCADE,
    FOREIGN KEY (Assembly_Child_ID) REFERENCES T_Assembly(Assembly_ID) ON DELETE CASCADE,
    FOREIGN KEY (Prototype_ID) REFERENCES T_Prototype(Prototype_ID) ON DELETE CASCADE
);