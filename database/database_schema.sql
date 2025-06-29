-- Shuttle Koi Database Schema
-- University Shuttle Management System

-- Drop database if exists and create new one
DROP DATABASE IF EXISTS shuttle_koi;
CREATE DATABASE shuttle_koi;
USE shuttle_koi;

-- Admin Table
CREATE TABLE Admin (
    A_ID INT PRIMARY KEY AUTO_INCREMENT,
    Name VARCHAR(255) NOT NULL,
    Email VARCHAR(255) UNIQUE NOT NULL,
    Password VARCHAR(255) NOT NULL,
    Role VARCHAR(100) DEFAULT 'Manager',
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Student Table
CREATE TABLE Student (
    S_ID INT PRIMARY KEY AUTO_INCREMENT,
    Name VARCHAR(255) NOT NULL,
    Email VARCHAR(255) UNIQUE NOT NULL,
    Password VARCHAR(255) NOT NULL,
    Phone_Number VARCHAR(15),
    A_ID INT,
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- Driver Table
CREATE TABLE Driver (
    D_ID INT PRIMARY KEY AUTO_INCREMENT,
    Name VARCHAR(255) NOT NULL,
    Email VARCHAR(255) UNIQUE NOT NULL,
    Password VARCHAR(255) NOT NULL,
    Phone_Number VARCHAR(15),
    License_Number VARCHAR(50),
    Status ENUM('Active', 'Inactive', 'On_Trip') DEFAULT 'Active',
    A_ID INT,
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- Stop Table
CREATE TABLE Stop (
    Stop_ID INT PRIMARY KEY AUTO_INCREMENT,
    Name VARCHAR(255) NOT NULL,
    Latitude DECIMAL(9,6) NOT NULL,
    Longitude DECIMAL(9,6) NOT NULL,
    Description TEXT,
    A_ID INT,
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- Route Table
CREATE TABLE Route (
    Route_ID INT PRIMARY KEY AUTO_INCREMENT,
    Name VARCHAR(255) NOT NULL,
    Description TEXT,
    A_ID INT,
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- Route_Stop Table (Many-to-Many relationship, defines stops for each route and their order)
CREATE TABLE Route_Stop (
    Route_ID INT,
    Stop_ID INT,
    Stop_Order INT NOT NULL, -- Order of the stop in the route
    PRIMARY KEY (Route_ID, Stop_ID),
    FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID) ON DELETE CASCADE,
    FOREIGN KEY (Stop_ID) REFERENCES Stop(Stop_ID) ON DELETE CASCADE
);

-- Shuttle Table
CREATE TABLE Shuttle (
    Shuttle_ID INT PRIMARY KEY AUTO_INCREMENT,
    Veh_Number VARCHAR(50) UNIQUE NOT NULL,
    Capacity INT NOT NULL,
    Model VARCHAR(100),
    Status ENUM('Active', 'Maintenance', 'Inactive') DEFAULT 'Active',
    A_ID INT,
    Created_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- Shuttle_Route Table (Many-to-Many relationship between Shuttle and Route)
CREATE TABLE Shuttle_Route (
    Shuttle_ID INT,
    Route_ID INT,
    PRIMARY KEY (Shuttle_ID, Route_ID),
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE CASCADE,
    FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID) ON DELETE CASCADE
);

-- Driver_Shuttle Assignment Table
CREATE TABLE Driver_Shuttle (
    D_ID INT,
    Shuttle_ID INT,
    Assigned_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (D_ID, Shuttle_ID),
    FOREIGN KEY (D_ID) REFERENCES Driver(D_ID) ON DELETE CASCADE,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE CASCADE
);

-- ShuttleLocation Table (Real-time tracking)
CREATE TABLE ShuttleLocation (
    Location_ID INT PRIMARY KEY AUTO_INCREMENT,
    Shuttle_ID INT,
    Latitude DECIMAL(9,6) NOT NULL,
    Longitude DECIMAL(9,6) NOT NULL,
    Speed DECIMAL(5,2),
    Direction DECIMAL(5,2),
    Timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE CASCADE
);

-- Trip Table (New table for tracking shuttle trips)
CREATE TABLE Trip (
    Trip_ID INT PRIMARY KEY AUTO_INCREMENT,
    Shuttle_ID INT NOT NULL,
    D_ID INT NOT NULL,
    Route_ID INT NOT NULL,
    Start_Time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    End_Time TIMESTAMP NULL,
    Start_Location_Lat DECIMAL(9,6),
    Start_Location_Lng DECIMAL(9,6),
    End_Location_Lat DECIMAL(9,6),
    End_Location_Lng DECIMAL(9,6),
    Distance_Km DECIMAL(8,2) DEFAULT 0.00,
    Duration_Minutes INT DEFAULT 0,
    Status ENUM('In_Progress', 'Completed', 'Cancelled') DEFAULT 'In_Progress',
    Passenger_Count INT DEFAULT 0,
    Notes TEXT,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE CASCADE,
    FOREIGN KEY (D_ID) REFERENCES Driver(D_ID) ON DELETE CASCADE,
    FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID) ON DELETE CASCADE
);

-- Shuttle Issue Table (New table for tracking shuttle problems)
CREATE TABLE ShuttleIssue (
    Issue_ID INT PRIMARY KEY AUTO_INCREMENT,
    Shuttle_ID INT NOT NULL,
    D_ID INT,
    Trip_ID INT,
    Issue_Type ENUM('Mechanical', 'Electrical', 'Tire', 'Fuel', 'Other') NOT NULL,
    Description TEXT NOT NULL,
    Severity ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
    Status ENUM('Reported', 'Under_Review', 'Being_Fixed', 'Resolved') DEFAULT 'Reported',
    Reported_At TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Resolved_At TIMESTAMP NULL,
    Estimated_Cost DECIMAL(10,2) DEFAULT 0.00,
    Actual_Cost DECIMAL(10,2) DEFAULT 0.00,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE CASCADE,
    FOREIGN KEY (D_ID) REFERENCES Driver(D_ID) ON DELETE SET NULL,
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID) ON DELETE SET NULL
);

-- Checkin Table
CREATE TABLE Checkin (
    CheckIn_ID INT PRIMARY KEY AUTO_INCREMENT,
    Timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    S_ID INT,
    Stop_ID INT,
    Shuttle_ID INT,
    Trip_ID INT,
    Status ENUM('Waiting', 'Boarded', 'Completed') DEFAULT 'Waiting',
    FOREIGN KEY (S_ID) REFERENCES Student(S_ID) ON DELETE CASCADE,
    FOREIGN KEY (Stop_ID) REFERENCES Stop(Stop_ID) ON DELETE CASCADE,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE CASCADE,
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID) ON DELETE SET NULL
);

-- QueueAlert Table
CREATE TABLE QueueAlert (
    Alert_ID INT PRIMARY KEY AUTO_INCREMENT,
    QueueCount INT NOT NULL,
    Threshold INT NOT NULL,
    Timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Stop_ID INT,
    D_ID INT,
    A_ID INT,
    Status ENUM('Active', 'Resolved') DEFAULT 'Active',
    FOREIGN KEY (Stop_ID) REFERENCES Stop(Stop_ID) ON DELETE CASCADE,
    FOREIGN KEY (D_ID) REFERENCES Driver(D_ID) ON DELETE SET NULL,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- ActivityLog Table
CREATE TABLE ActivityLog (
    Log_ID INT PRIMARY KEY AUTO_INCREMENT,
    Action ENUM('StartTrip', 'EndTrip', 'Pause', 'Resume', 'ArriveAtStop', 'DepartFromStop') NOT NULL,
    Timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    D_ID INT,
    Stop_ID INT,
    Route_ID INT,
    Shuttle_ID INT,
    Trip_ID INT,
    A_ID INT,
    Notes TEXT,
    FOREIGN KEY (D_ID) REFERENCES Driver(D_ID) ON DELETE SET NULL,
    FOREIGN KEY (Stop_ID) REFERENCES Stop(Stop_ID) ON DELETE SET NULL,
    FOREIGN KEY (Route_ID) REFERENCES Route(Route_ID) ON DELETE SET NULL,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE SET NULL,
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID) ON DELETE SET NULL,
    FOREIGN KEY (A_ID) REFERENCES Admin(A_ID) ON DELETE SET NULL
);

-- Feedback Table
CREATE TABLE Feedback (
    Feedback_ID INT PRIMARY KEY AUTO_INCREMENT,
    Rating INT CHECK (Rating >= 1 AND Rating <= 5) NOT NULL,
    Comment TEXT,
    Timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    S_ID INT,
    Shuttle_ID INT,
    D_ID INT,
    Trip_ID INT,
    FOREIGN KEY (S_ID) REFERENCES Student(S_ID) ON DELETE CASCADE,
    FOREIGN KEY (Shuttle_ID) REFERENCES Shuttle(Shuttle_ID) ON DELETE SET NULL,
    FOREIGN KEY (D_ID) REFERENCES Driver(D_ID) ON DELETE SET NULL,
    FOREIGN KEY (Trip_ID) REFERENCES Trip(Trip_ID) ON DELETE SET NULL
);

-- Notification Table
CREATE TABLE Notification (
    Notification_ID INT PRIMARY KEY AUTO_INCREMENT,
    Recipient_type ENUM('Student', 'Driver', 'Admin') NOT NULL,
    Type ENUM('ArrivalAlert', 'QueueAlert', 'SystemAlert', 'FeedbackAlert') NOT NULL,
    Message TEXT NOT NULL,
    Status ENUM('Pending', 'Sent', 'Failed') DEFAULT 'Pending',
    Timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Recipient_id INT,
    Read_Status BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (Recipient_id) REFERENCES Student(S_ID) ON DELETE CASCADE
);

-- Insert default admin account
INSERT INTO Admin (Name, Email, Password, Role) VALUES 
('System Admin', 'admin@shuttlekoi.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin');

-- Insert sample data for testing
INSERT INTO Stop (Name, Latitude, Longitude, Description, A_ID) VALUES 
('Main Campus', 40.7128, -74.0060, 'Main university entrance'),
('Student Center', 40.7130, -74.0062, 'Student activity center'),
('Library', 40.7126, -74.0058, 'University library'),
('Sports Complex', 40.7140, -74.0065, 'Athletic facilities');

INSERT INTO Route (Name, Description, A_ID) VALUES 
('Route A - Main Loop', 'Main campus circular route', 1),
('Route B - Express', 'Direct route to main buildings', 1);

INSERT INTO Route_Stop (Route_ID, Stop_ID, Stop_Order) VALUES 
(1, 1, 1), (1, 2, 2), (1, 3, 3), (1, 4, 4),
(2, 1, 1), (2, 3, 2);

INSERT INTO Shuttle (Veh_Number, Capacity, Model, A_ID) VALUES 
('SK001', 20, 'Ford Transit', 1),
('SK002', 25, 'Mercedes Sprinter', 1),
('SK003', 15, 'Toyota Hiace', 1); 