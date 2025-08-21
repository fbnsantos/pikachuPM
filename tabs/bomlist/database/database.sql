CREATE TABLE IF NOT EXISTS T_Manufacturer (
    Manufacturer_ID INT AUTO_INCREMENT PRIMARY KEY,
    Denomination VARCHAR(255) NOT NULL,
    Origin_Country VARCHAR(100),
    Website VARCHAR(255),
    Contacts TEXT,
    Address TEXT,
    Notes TEXT,
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Updated_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS T_Supplier (
    Supplier_ID INT AUTO_INCREMENT PRIMARY KEY,
    Denomination VARCHAR(255) NOT NULL,
    Origin_Country VARCHAR(100),
    Website VARCHAR(255),
    Contacts TEXT,
    Address TEXT,
    Notes TEXT,
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
    Reference VARCHAR(9) NOT NULL,
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

-- Tabela com os dados básicos da assembly
CREATE TABLE IF NOT EXISTS T_Assembly (
    Assembly_ID INT AUTO_INCREMENT PRIMARY KEY,
    Prototype_ID INT NOT NULL,
    Assembly_Designation VARCHAR(255) NOT NULL,
    Assembly_Reference VARCHAR(9) NOT NULL,
    Assembly_Level INT DEFAULT 0,
    Price FLOAT NOT NULL DEFAULT 0,
    Notes TEXT,
    Created_Date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Prototype_ID) REFERENCES T_Prototype(Prototype_ID) ON DELETE CASCADE
);

-- Tabela para relação de assembly com múltiplos componentes
CREATE TABLE IF NOT EXISTS T_Assembly_Component (
    Assembly_ID INT NOT NULL,
    Component_ID INT NOT NULL,
    Quantity INT NOT NULL DEFAULT 1,
    PRIMARY KEY (Assembly_ID, Component_ID),
    FOREIGN KEY (Assembly_ID) REFERENCES T_Assembly(Assembly_ID) ON DELETE CASCADE,
    FOREIGN KEY (Component_ID) REFERENCES T_Component(Component_ID) ON DELETE CASCADE
);

-- Tabela para relação de uma assembly com outras assemblies (por exemplo, subassemblies)
CREATE TABLE IF NOT EXISTS T_Assembly_Assembly (
    Parent_Assembly_ID INT NOT NULL,
    Child_Assembly_ID INT NOT NULL,
    Quantity INT NOT NULL DEFAULT 1,
    PRIMARY KEY (Parent_Assembly_ID, Child_Assembly_ID),
    FOREIGN KEY (Parent_Assembly_ID) REFERENCES T_Assembly(Assembly_ID) ON DELETE CASCADE,
    FOREIGN KEY (Child_Assembly_ID) REFERENCES T_Assembly(Assembly_ID) ON DELETE CASCADE
);