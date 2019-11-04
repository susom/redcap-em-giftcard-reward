# Gift Card Rewards

An External Module to help automate the dispersement of gift cards for used in projects offering rewards.

This module requires two REDCap projects: 1) the project where study data is entered and 2) a gift card library repository project where 
gift cards are loaded for dispersement to participants meeting study requirements.  These two projects work in tandem to disperse gift cards
at the proper time.  The gift card library project can be used for more than one gift card study project.

The EM is enabled for the gift card study project (not the gift card library). As part of the setup process, the gift card library must
 be specified in the gift card library configuration. 



# Setup
To setup a gift card configuration for a project, follow these steps:

    - Enable this EM to the gift card project
    - Edit the EM configuration:
    - 
    - Select the Gift Card Library project (separate project that may hold gift cards for more than one project)
    - Create a gift card instance for each type of reward (Initial Survey, Completion, etc.)
    - Make sure there are no errors displayed at the top of the modal and Save.
        
        
    - Go to the Gift Card Library Project
    - Load rewards into project (one per record)
    - All fields above Status header should be filled for each record
    - Everything below the Status header will be filled in when the card is awarded
    
# Reward Processing

Each time a record is saved in the Gift Card Project, a check will be performed to see if the participant is eligible for a reward. Once a participant is found eligible, the following steps will be performed:

    1) An unused reward meeting the monetary requirement in the Gift Card Library will be found and reserved for this participant
    2) The participant will receive an email with a link included on how to access their gift card
    3) Once the participant clicks on the link, the reward will be display on the webpage and an email will be sent with the same gift card information
    4) The record holding the reward in the Gift Card Library project will have a status of Claimed with a timestamp
    5) The record holding the participant information in the Gift Card Project will have the status Claimed
    
# Future Enhancements
    - Add the ability for the projects to download a standard Gift Card Library Template
    - Add the ability to rate limit the number of cards that can be awarded per day
    - Add the option to use a cron job to check the reward logic