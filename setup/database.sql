CREATE TABLE config (adminGroups VARCHAR(128));
CREATE TABLE events (id INT AUTO_INCREMENT, description VARCHAR(128), maxTeams INT, minTeamSize INT, maxTeamSize INT, startTime TIMESTAMP DEFAULT CURRENT_TIMESTAMP, isClosed BOOL DEFAULT FALSE, registrationBuffer INT, memberGroups VARCHAR(128), PRIMARY KEY(id));
CREATE TABLE users (bzid INT, callsign VARCHAR(128), banned BOOL DEFAULT FALSE, lastEvent INT);
CREATE TABLE teams (id INT AUTO_INCREMENT, event INT, sufficiencyTime TIMESTAMP NULL, PRIMARY KEY (id));
CREATE TABLE memberships (team INT, bzid INT, rating INT);
CREATE TABLE results (event INT, matchNumber INT, team1Score INT, team2Score INT, disqualifyTeam INT DEFAULT 0);
