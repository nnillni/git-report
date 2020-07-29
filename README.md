# git-report

This tool allows for a better analysis of the commits made on a repository.

## Requirements

1. PHP-CLI
2. Command line python

## Installation

1. **Clone the branch** in your filesystem
2. Execute the installation script: `sh install.sh`

## Usage

Please refer to [git-standup](https://github.com/kamranahmedse/git-standup) for the usage. The next table shows some of the common usages:

| Command | Output |
| :---: | :---: | 
| git report | Report of your last day commits | 
| git report -a "Anna" | Report of Anna's last day commits | 
| git report -a "all" -d 3| Report of everyone's commits for the past 3 days |

