# Gift Card Rewards External Module

This module will automate the dispersement of gift cards for use in projects offering incentive rewards.

This module requires two REDCap projects: 1) the project where study data is stored (study project), and 2) a gift card library 
repository project where gift cards are loaded for dispersement (library project).  These two projects work in tandem to disperse
gift cards at the proper time as specified by logic in the configuration file.  The gift card library project can be used for more
than one gift card study project.

The module must be enabled for the study project (not the gift card library). 

## Background
Many research studies offer incentives to participants, in the form of gift cards, at different time points. Tracking and distribution of the gift cards can be a 
very time-consuming task, especially with remote participants. To help alleivate the burdon of tracking eligible participants and facilitate distribution of the
gift cards, this External Module was created.  This module also helps with gift card reporting for study administration.

## Overview
This EM allows multiple configurations to be setup for each timepoint when gift cards can be rewarded. Each configuration
allows logic to be evaluated to determine when a participant is eligible and specifies the type and denomination of
the gift card.  

When a participant is deemed eligible, they will be sent a customizable email to let them know they received
an award with a personalized link.  Once they click on the link in the email, the gift card information, needed to redeem the reward,
will be displayed to them on a webpage. There is an option to send this information to an email address - either theirs or someone elses.

## How does it work?
This module is designed to evaluate configuration specific logic (based on REDCap field values) when a record is saved. When the logic becomes true, a reward is
processed for the participant. The reward processing consists of looking for an available gift card in the library project, reserving that gift card in the
library project and notifying the participant via email that they were awarded a gift card. The gift card library record gift card
status will be set to 'Reserved' with a timestamp. 

The configuration file specifies a gift card denomination to give for each timepoint and gift cards can be filtered by brand.
The brand filter is performed by creating a radio button or drop down field in the study project to hold the brands available for distribution.
The description of each radio selection must match the brand entered in the library project. The denomination amount entered in the 
External Module module configuration should only include the dollar amount and not the dollar sign (20 and not $20.00) in order to match the library project entry.

A few items of information are saved in the library project when a reward is distributed, such as,
the name of the reward (which is specified in the study project External Module configuration), and the project id (in case more than 1 study project
uses the same gift card library), record in the study project that is being awarded the reward and the email address where the
gift card codes were sent. 

The study project will save the 'Reserved' status of the gift card and the record of the library project which contains the reward being sent to this
participant. Enough information is saved in the library and study projects to easily manuver back and forth between the two projects.

## Participant View

Once a participant reaches a reward milestone, they will receive an email with customizable text. At the bottom of the email a link to a webpage that
will display their reward is inserted. This email text is specified in the External Module configuration file and can use piping for personalization.

Once the participant clicks on the email link, a webpage will be launched that contains their reward code.  From this webpage, participants
will be able to email that reward code to themselves (or someone else) so they have a copy of the code. The email address that is sent a copy of the
reward code will be stored in the library project.

## Features
This module is able to send multiple rewards per project.  For instance, if your study grants a reward after filling out a Baseline
Questionnaire, after Week 3 and at the end of the study, each of these reward timeframes can be setup in the gift card External Module configuration. There
are no limits to the number of rewards one study project can gift.  Each reward configuration must use the same library project.

A new feature to this module is the ability to evaluate the reward logic from a cron job once a day currently set to 9am.  When this option is selected in 
the configuration setup, a cron job will run to evaluate gift card logic and determine if participants qualify for a reward.
The use case for using a cron job is when logic is date dependent.

Another way to send out gift cards is to batch process them.  When batch processing is enabled, records will not be evaluated
for gift cards during a record save event.  Instead, an External Module webpage is provided to display which records are ready for gift cards
and only those records selected on the webpage will be processed and sent a card.  Batch Processing can be used to rate limit 
the number of cards that go out each day or it can provide extra time to ensure each record is valid before sending out rewards.

Unless a project opts out, there is a daily summary that is sent to the Alert Email address which summarizes the status of the gift card
dispersement for the previous day.  If more than one configuration is setup for a study project, one email will be sent summarizing all
configuration setups.

Some projects may want the ability to time limit the availability of the rewards.  For instance, you can make the award valid for 7 days, 
after that time the participant will lose the ability to see their reward information.  This scenario can be accomplished when using 
gift codes (not links to gift codes) by resetting awards that are in 'Reserved' status back to 'Ready' status at the end of the 7th day.


## Setup

### Gift Card Library Setup
The gift card library project must have the following fields:
![LibraryProject](img/library_project.png)

There is an xml and csv template in Github which <b>should</b> be used for the library project. The gift card information, 
which is dispersed to participants, can be imported into the project from a csv file.

The gift card library project supports several statuses for each gift card record.  When the information is entered into the
library but is not yet ready to be sent, the status of 'Not Ready' can be used.  When the gift cards are available to be
used for the project, they are set in 'Ready' status.  When a participant becomes eligible for a reward and is sent a
reward email with a link to the reward, the status becomes 'Reserved'.  When the link in the email is selected and the 
reward is displayed on a webpage, the status is 'Claimed'. In this case, the status of Claimed does not mean it was redeemed, it
just means that the participant has the information to redeem the gift card.

The gift card library project supports sending participants the actual reward code so they can redeem the reward.  It also
supports entering a link to a third party website where they can redeem the reward.  If the value entered in the
<i>[egift_number]</i> field starts with 'http', then the value of the field will be setup as a link in the email so
participants can click on the link to retrieve their reward from the 3rd party site. When using links, the redemption 
of the reward is outside of Redcap so the status of the reward will stay at 'Reserved' and will not change to 'Claimed'.

When the reward is an alphanumeric string, then Redcap will track when the user clicks on the link in the email and turns
the status to 'Claimed'.

If a gift card was erroneously sent out, the gift card record can be reset to 'Ready' and the record can be re-used. The
gift card can only be redeemed with a valid token so if the token is deleted, that gift card can no longer be 'Claimed'.

### Project Configuration File

![ExternalModule](img/external_module.png)


Once the module is enabled for the gift card project, the External Module configuration file must be filled out.
![ConfigurationFile](img/open_config.png)

To setup a gift card configuration for a project, there are 3 main sections, described below.

### Reward Library

The Reward Library section requires you to enter information on the location of the library project. This project can be used for more than one
gift card project configuration but the same library project must be used for each reward of the study project.

### Reward Configuration

There may be many configurations created if there are multiple rewards dispersed by this project.
Each configuration requires information about the specific reward. Among the required information are the reward logic, reward title and the
field which holds the email address of the participant.

### Notification Settings

The Notification Settings section determines when notifications will be sent to the study project Alert Email. 
For instance, you can specify at which point you would like to be notified when the gift card inventory drops
below a threshold.

### Reward Processing

Each time a record is saved in the Gift Card Project, a check will be performed to see if the participant is eligible for a reward. 
Once a participant is found eligible, the following steps will be performed:

`    1) An unused reward record meeting the monetary (and brand) requirement(s) in the Gift Card Library, will be placed in Reserved status for this card entry.
    2) The participant will receive an email with a link included to their reward gift card
    3) Once the participant clicks on the link, the reward will be display on a webpage
    4) The Gift Card Library project record will update the status of the record to Claimed with a timestamp
    5) The Gift Card Project record will update the reward status to Claimed
`    
Once the participant clicks on the link received in the rewards email, the reward display appears as follows:

![RewardDisplay](img/reward_display.png)

Participants have the option to send themselves or someone else a copy of the reward number for future reference.

## Cron Processing

There are 2 cron jobs running daily:

### Gift Card Summary

The Summary cron job will send a daily update at 6am to the Alert Email address specified in the configuration file.  The nightly summary 
displays the following data:

![Nightly Summary](img/daily_summary.png)

### Logic Checker

The Logic Checker cron job will run at 9am each morning and look for the configurations who have the "Enable 9am cron 
job to check logic" checkbox enabled. Configurations should enable this cron logic checker only when they have a date 
component to their reward logic.  This cron will ensure the
reward is sent on the appropriate date even if the record is not saved on the day it is eligible.

## Batch Processing

The Batch Processing webpage will display all records that are ready for a reward for each configuration that has 
selected batch processing. The user will be able to select the records that should receive the reward for each configuration.

![Batch Processing](img/batch_processing.png)

## Uploading gift card rewards to the Library

To upload gift card rewards to the Library, the Data Importer can be used by following the steps below.
Create a .csv file with the following headers: reward_id, brand, egift_number, challenge_code, amount, status 
- The <b>reward_id</b>  is the record number
- The <b>brand</b> is the company name for the gift card.  If using brand filtering, this name must exactly match the coded value brand label in the gift card project.
- The <b>egift_number</b> is either the gift card number used to redeem the gift card or it can be an URL to a 3rd party supplier provided gift card link.
- The <b>challenge_code</b> is an optional field which can be used if the gift card requires a PIN or secondary value in order to redeem the card.
- The <b>status</b> field should be set to 0 if the gift cards are not ready to be or 1 if the gift cards are ready to be sent.
`
Once the .csv file is complete, use the Data Importer to load the data into your Gift Card Library project,

## NOTES:
* This External Module uses the emLogger External Module developed at Stanford to log processing messages.
* This External Module creates a database table called <b>redcap_em_lock</b>. This External Module will retrieve the semaphore in the
table to make sure only one record can claim a particular reward. Once a record is eligible for a reward, this EM will claim a reward from the library
and keep the semaphore until the status of the reward is changed to Reserved.  At that time, the semaphore is released and the next process 
can claim the semaphore and process a reward.

## Future Enhancements
    - Add the ability for projects to download a standard Gift Card Library Template automatically